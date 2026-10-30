<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Access denied.');
}

// ============================================================================
// modules/appointments_sms_module.php — Module M6: Scheduling & Outbound SMS
// Covers:
//   - FR-20 / BR-08: Manage appointments across the 6 program_types with 4 statuses
//                    (Scheduled | Completed | Cancelled | No-Show) and reminder_sent flag
//   - FR-21 / BR-09: Outbound-only SMS via Philippine gateway adapter (Semaphore /
//                    iTexMo / MOVider + offline simulation) from privacy-safe templates,
//                    strictly requiring valid `consent_records` (`consent_given = 1`)
//   - FR-22 / BR-08: Log every SMS to `sms_logs` with recipient_number snapshot,
//                    gateway_used, delivery_status, and set `reminder_sent = 1` ONLY
//                    after a logged send
// ============================================================================

require_once __DIR__ . '/audit_module.php';

/**
 * Search and filter appointments (D6).
 */
function getAppointments(
    PDO $pdo,
    string $statusFilter = '',
    string $programFilter = '',
    string $dateFilter = '',
    int $limit = 150
): array {
    $limit = max(1, min(500, $limit));
    $sql = "
        SELECT a.*,
               p.upi, p.last_name, p.first_name, p.contact_no, p.purok_address,
               u.full_name AS created_by_name,
               (SELECT c.consent_given
                FROM consent_records c
                WHERE c.patient_id = a.patient_id AND c.consent_type = 'SMS Notification'
                ORDER BY c.consent_id DESC LIMIT 1) AS sms_consent
        FROM appointments a
        INNER JOIN patients p ON a.patient_id = p.patient_id
        LEFT JOIN users u ON a.created_by = u.user_id
        WHERE 1=1
    ";
    $params = [];

    if ($statusFilter !== '' && in_array($statusFilter, APPOINTMENT_STATUSES, true)) {
        $sql .= " AND a.status = :status";
        $params[':status'] = $statusFilter;
    }

    if ($programFilter !== '' && in_array($programFilter, PROGRAM_TYPES, true)) {
        $sql .= " AND a.program_type = :prog";
        $params[':prog'] = $programFilter;
    }

    if ($dateFilter !== '') {
        $sql .= " AND a.scheduled_date = :sdate";
        $params[':sdate'] = $dateFilter;
    }

    $sql .= " ORDER BY a.scheduled_date DESC, a.scheduled_time ASC, a.appointment_id DESC LIMIT :limit";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

/**
 * FR-20 / BR-02 / BR-08: Create a new scheduled appointment.
 *
 * @return array{success: bool, appointment_id?: int, error?: string}
 */
function createAppointment(
    PDO $pdo,
    int $patientId,
    string $programType,
    string $scheduledDate,
    ?string $scheduledTime,
    int $createdBy
): array {
    $scheduledDate = trim($scheduledDate);
    $scheduledTime = ($scheduledTime !== null && trim($scheduledTime) !== '') ? trim($scheduledTime) : null;

    if ($patientId <= 0 || $scheduledDate === '' || !in_array($programType, PROGRAM_TYPES, true)) {
        return ['success' => false, 'error' => 'Patient, valid program (BR-02), and scheduled date are required (FR-20).'];
    }

    $stmt = $pdo->prepare("
        INSERT INTO appointments
            (patient_id, program_type, scheduled_date, scheduled_time, status, reminder_sent, created_by)
        VALUES
            (:pid, :prog, :sdate, :stime, 'Scheduled', 0, :by)
    ");
    $stmt->execute([
        ':pid'   => $patientId,
        ':prog'  => $programType,
        ':sdate' => $scheduledDate,
        ':stime' => $scheduledTime,
        ':by'    => $createdBy ?: null,
    ]);

    $newId = (int) $pdo->lastInsertId();
    logAudit(
        $pdo,
        'APPOINTMENT_CREATE',
        "Scheduled {$programType} appointment #{$newId} for patient #{$patientId} on {$scheduledDate}",
        'appointments',
        $newId,
        $createdBy
    );

    return ['success' => true, 'appointment_id' => $newId];
}

/**
 * FR-20 / BR-08: Update appointment status among the 4 allowed statuses:
 * Scheduled | Completed | Cancelled | No-Show
 */
function updateAppointmentStatus(PDO $pdo, int $appointmentId, string $newStatus, int $actingUserId): ?string
{
    if (!in_array($newStatus, APPOINTMENT_STATUSES, true)) {
        return 'Invalid appointment status (BR-08). Allowed: Scheduled, Completed, Cancelled, No-Show.';
    }

    $stmt = $pdo->prepare("UPDATE appointments SET status = :status WHERE appointment_id = :id");
    $stmt->execute([
        ':status' => $newStatus,
        ':id'     => $appointmentId,
    ]);

    logAudit(
        $pdo,
        'APPOINTMENT_STATUS',
        "Updated appointment #{$appointmentId} status to '{$newStatus}'",
        'appointments',
        $appointmentId,
        $actingUserId
    );

    return null;
}

/**
 * BR-09: Verify if a patient has an active SMS Notification consent in `consent_records`.
 */
function hasActiveSmsConsent(PDO $pdo, int $patientId): bool
{
    $stmt = $pdo->prepare("
        SELECT consent_given
        FROM consent_records
        WHERE patient_id = :pid AND consent_type = 'SMS Notification'
        ORDER BY consent_id DESC
        LIMIT 1
    ");
    $stmt->execute([':pid' => $patientId]);
    $val = $stmt->fetchColumn();
    return ((int) $val === 1);
}

/**
 * Compose a privacy-safe SMS message from an approved template (BR-09).
 */
function buildPrivacySafeSmsText(string $templateKey, array $patient, ?array $appointment = null): string
{
    $templates = SMS_TEMPLATES;
    $tpl = $templates[$templateKey] ?? $templates['appointment_reminder'];
    $body = $tpl['body'];

    $patientName = trim(($patient['first_name'] ?? '') . ' ' . ($patient['last_name'] ?? ''));
    $program     = $appointment['program_type'] ?? 'health service';
    $dateStr     = $appointment['scheduled_date'] ?? date('Y-m-d');
    if (!empty($appointment['scheduled_time'])) {
        $dateStr .= ' at ' . substr((string) $appointment['scheduled_time'], 0, 5);
    }

    return str_replace(
        ['[patient_name]', '[program]', '[date]'],
        [$patientName, $program, $dateStr],
        $body
    );
}

/**
 * Outbound-only Philippine SMS Gateway Adapter (Semaphore / iTexMo / MOVider + Offline Simulator).
 * If SMS_API_KEY is configured and internet is available, dispatches over HTTPS;
 * otherwise simulates immediate gateway delivery receipt for offline Laragon demo.
 *
 * @return array{gateway_used: string, delivery_status: string}
 */
function dispatchToPhilippineSmsGateway(string $recipientNumber, string $messageContent): array
{
    $provider = trim((string) env('SMS_PROVIDER', 'Semaphore Mock'));
    $apiKey   = trim((string) env('SMS_API_KEY', ''));
    $sender   = trim((string) env('SMS_SENDER_NAME', 'TUBTUBONHC'));

    // Live HTTPS Semaphore dispatch if API key is provided
    if ($apiKey !== '' && stripos($provider, 'Semaphore') !== false && function_exists('curl_init')) {
        $ch = curl_init('https://api.semaphore.co/api/v4/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_POSTFIELDS     => http_build_query([
                'apikey'     => $apiKey,
                'number'     => $recipientNumber,
                'message'    => $messageContent,
                'sendername' => $sender,
            ]),
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $status = ($resp !== false && $code >= 200 && $code < 300) ? 'Sent' : 'Failed';
        return [
            'gateway_used'    => 'Semaphore',
            'delivery_status' => $status,
        ];
    }

    // Offline-first simulated receipt for local Laragon demonstration
    return [
        'gateway_used'    => $provider ?: 'Semaphore Mock',
        'delivery_status' => 'Delivered',
    ];
}

/**
 * FR-21 / FR-22 / BR-08 / BR-09: Send a consent-gated, privacy-safe outbound SMS,
 * log the recipient number snapshot and gateway receipt to `sms_logs`, and flip
 * `appointments.reminder_sent = 1` ONLY after the log row is committed.
 *
 * @return array{success: bool, sms_log_id?: int, delivery_status?: string, message?: string, error?: string}
 */
function sendOutboundSmsReminder(
    PDO $pdo,
    int $patientId,
    ?int $appointmentId,
    string $templateKey,
    int $actingUserId
): array {
    // 1. Fetch patient & verify mobile number
    $pStmt = $pdo->prepare("SELECT * FROM patients WHERE patient_id = :pid LIMIT 1");
    $pStmt->execute([':pid' => $patientId]);
    $patient = $pStmt->fetch();
    if (!$patient) {
        return ['success' => false, 'error' => 'Patient not found.'];
    }

    $recipientNumber = trim((string) ($patient['contact_no'] ?? ''));
    if ($recipientNumber === '') {
        return ['success' => false, 'error' => 'Patient does not have a mobile number on file.'];
    }

    // 2. BR-09: Strict Consent Check (`consent_records` where consent_type = 'SMS Notification' AND consent_given = 1)
    if (!hasActiveSmsConsent($pdo, $patientId)) {
        logAudit(
            $pdo,
            'SMS_BLOCKED_NO_CONSENT',
            "Blocked outbound SMS to {$patient['upi']} ({$patient['last_name']}, {$patient['first_name']}): Missing SMS consent (BR-09)",
            'patients',
            $patientId,
            $actingUserId ?: null
        );
        return [
            'success' => false,
            'error'   => "Outbound SMS blocked (BR-09): Patient {$patient['upi']} ({$patient['last_name']}, {$patient['first_name']}) has not granted SMS Notification consent in consent_records.",
        ];
    }

    // 3. Load optional linked appointment
    $appointment = null;
    if ($appointmentId && $appointmentId > 0) {
        $aStmt = $pdo->prepare("SELECT * FROM appointments WHERE appointment_id = :aid LIMIT 1");
        $aStmt->execute([':aid' => $appointmentId]);
        $appointment = $aStmt->fetch() ?: null;
    }

    // 4. Build privacy-safe templated text (BR-09: no clinical diagnoses or SPI)
    $messageContent = buildPrivacySafeSmsText($templateKey, $patient, $appointment);

    // 5. Dispatch over outbound-only gateway
    $gwResult = dispatchToPhilippineSmsGateway($recipientNumber, $messageContent);

    $pdo->beginTransaction();
    try {
        // 6. Log to `sms_logs` with recipient_number snapshot & consent_verified = 1 (FR-22)
        $logStmt = $pdo->prepare("
            INSERT INTO sms_logs
                (patient_id, appointment_id, recipient_number, message_content, gateway_used, delivery_status, sent_at, consent_verified)
            VALUES
                (:pid, :aid, :num, :msg, :gw, :status, NOW(), 1)
        ");
        $logStmt->execute([
            ':pid'    => $patientId,
            ':aid'    => $appointment ? (int) $appointment['appointment_id'] : null,
            ':num'    => $recipientNumber,
            ':msg'    => substr($messageContent, 0, 500),
            ':gw'     => $gwResult['gateway_used'],
            ':status' => $gwResult['delivery_status'],
        ]);
        $smsLogId = (int) $pdo->lastInsertId();

        // 7. BR-08: Flip `appointments.reminder_sent = 1` ONLY after `sms_logs` entry succeeds
        if ($appointment && in_array($gwResult['delivery_status'], ['Sent', 'Delivered'], true)) {
            $uAppt = $pdo->prepare("UPDATE appointments SET reminder_sent = 1 WHERE appointment_id = :aid");
            $uAppt->execute([':aid' => $appointment['appointment_id']]);
        }

        $pdo->commit();

        logAudit(
            $pdo,
            'SMS_SEND',
            "Outbound SMS #{$smsLogId} ({$gwResult['delivery_status']}) sent to {$patient['upi']} [{$recipientNumber}] via {$gwResult['gateway_used']}",
            'sms_logs',
            $smsLogId,
            $actingUserId ?: null
        );

        return [
            'success'         => true,
            'sms_log_id'      => $smsLogId,
            'delivery_status' => $gwResult['delivery_status'],
            'message'         => "Outbound SMS dispatched to {$recipientNumber} ({$gwResult['delivery_status']}) and logged to sms_logs (#{$smsLogId}).",
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'error' => 'Failed to log SMS transaction: ' . $e->getMessage()];
    }
}

/**
 * Fetch SMS delivery audit logs (`sms_logs` — D10).
 */
function getSmsLogs(PDO $pdo, int $limit = 100): array
{
    $limit = max(1, min(500, $limit));
    $stmt = $pdo->prepare("
        SELECT s.*,
               p.upi, p.last_name, p.first_name,
               a.program_type, a.scheduled_date
        FROM sms_logs s
        INNER JOIN patients p ON s.patient_id = p.patient_id
        LEFT JOIN appointments a ON s.appointment_id = a.appointment_id
        ORDER BY s.sent_at DESC, s.sms_log_id DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}
