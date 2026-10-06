/**
 * mock-data.js
 * Tubtubon eHealth - SYNTHETIC DEMO DATA
 * All data is fictional for demonstration purposes only.
 * No real patient information is used.
 */

"use strict";

// ─────────────────────────────────────────────
//  PATIENTS  (12 mock Filipino residents)
// ─────────────────────────────────────────────
window.MOCK_PATIENTS = [
  {
    upi: "TUB-2026-00001",
    last_name: "DELA CRUZ",
    first_name: "Juana",
    middle_name: "Santos",
    sex: "Female",
    birthdate: "1990-03-15",
    purok: "Purok 1 - Sampaguita",
    address: "Brgy. Tubtubon, Negros Oriental",
    contact: "09171234001",
    guardian: "Pedro Dela Cruz",
    guardian_contact: "09171234002",
    philhealth: "PH-0001-2026",
    consent_sms: true,
    notes: "Resident portal demo account"
  },
  {
    upi: "TUB-2026-00002",
    last_name: "REYES",
    first_name: "Maria",
    middle_name: "Lim",
    sex: "Female",
    birthdate: "1985-07-22",
    purok: "Purok 2 - Rosal",
    address: "Brgy. Tubtubon, Negros Oriental",
    contact: "09181234002",
    guardian: "Jose Reyes",
    guardian_contact: "09181234003",
    philhealth: "PH-0002-2026",
    consent_sms: true,
    notes: ""
  },
  {
    upi: "TUB-2026-00003",
    last_name: "SANTOS",
    first_name: "Ana",
    middle_name: "Garcia",
    sex: "Female",
    birthdate: "1995-11-08",
    purok: "Purok 3 - Waling-waling",
    address: "Brgy. Tubtubon, Negros Oriental",
    contact: "09271234003",
    guardian: "Roberto Santos",
    guardian_contact: "09271234004",
    philhealth: "PH-0003-2026",
    consent_sms: false,
    notes: ""
  },
  {
    upi: "TUB-2026-00004",
    last_name: "GARCIA",
    first_name: "Lourdes",
    middle_name: "Cruz",
    sex: "Female",
    birthdate: "1978-05-30",
    purok: "Purok 4 - Ilang-ilang",
    address: "Brgy. Tubtubon, Negros Oriental",
    contact: "09321234004",
    guardian: "",
    guardian_contact: "",
    philhealth: "PH-0004-2026",
    consent_sms: true,
    notes: ""
  },
  {
    upi: "TUB-2026-00005",
    last_name: "MARTINEZ",
    first_name: "Rosario",
    middle_name: "Bautista",
    sex: "Female",
    birthdate: "2000-01-10",
    purok: "Purok 1 - Sampaguita",
    address: "Brgy. Tubtubon, Negros Oriental",
    contact: "09191234005",
    guardian: "Elena Martinez",
    guardian_contact: "09191234006",
    philhealth: "PH-0005-2026",
    consent_sms: true,
    notes: ""
  },
  {
    upi: "TUB-2026-00006",
    last_name: "BAUTISTA",
    first_name: "Carmelita",
    middle_name: "Ramos",
    sex: "Female",
    birthdate: "1992-09-25",
    purok: "Purok 2 - Rosal",
    address: "Brgy. Tubtubon, Negros Oriental",
    contact: "09231234006",
    guardian: "",
    guardian_contact: "",
    philhealth: "PH-0006-2026",
    consent_sms: false,
    notes: ""
  },
  {
    upi: "TUB-2026-00007",
    last_name: "FLORES",
    first_name: "Teresita",
    middle_name: "Aquino",
    sex: "Female",
    birthdate: "1988-12-03",
    purok: "Purok 5 - Dama de Noche",
    address: "Brgy. Tubtubon, Negros Oriental",
    contact: "09451234007",
    guardian: "",
    guardian_contact: "",
    philhealth: "PH-0007-2026",
    consent_sms: true,
    notes: ""
  },
  {
    upi: "TUB-2026-00008",
    last_name: "RAMOS",
    first_name: "Natividad",
    middle_name: "Villanueva",
    sex: "Female",
    birthdate: "1975-04-18",
    purok: "Purok 3 - Waling-waling",
    address: "Brgy. Tubtubon, Negros Oriental",
    contact: "09561234008",
    guardian: "Antonio Ramos",
    guardian_contact: "09561234009",
    philhealth: "PH-0008-2026",
    consent_sms: true,
    notes: ""
  },
  {
    upi: "TUB-2026-00009",
    last_name: "VILLANUEVA",
    first_name: "Corazon",
    middle_name: "Mendoza",
    sex: "Female",
    birthdate: "1998-06-14",
    purok: "Purok 4 - Ilang-ilang",
    address: "Brgy. Tubtubon, Negros Oriental",
    contact: "09671234009",
    guardian: "",
    guardian_contact: "",
    philhealth: "PH-0009-2026",
    consent_sms: true,
    notes: ""
  },
  {
    upi: "TUB-2026-00010",
    last_name: "MENDOZA",
    first_name: "Ligaya",
    middle_name: "Torres",
    sex: "Male",
    birthdate: "2020-08-05",
    purok: "Purok 1 - Sampaguita",
    address: "Brgy. Tubtubon, Negros Oriental",
    contact: "09781234010",
    guardian: "Fe Mendoza",
    guardian_contact: "09781234011",
    philhealth: "PH-0010-2026",
    consent_sms: false,
    notes: "Child patient"
  },
  {
    upi: "TUB-2026-00011",
    last_name: "AQUINO",
    first_name: "Catalina",
    middle_name: "Hernandez",
    sex: "Female",
    birthdate: "1983-02-28",
    purok: "Purok 5 - Dama de Noche",
    address: "Brgy. Tubtubon, Negros Oriental",
    contact: "09891234011",
    guardian: "",
    guardian_contact: "",
    philhealth: "PH-0011-2026",
    consent_sms: true,
    notes: ""
  },
  {
    upi: "TUB-2026-00017",
    last_name: "HERNANDEZ",
    first_name: "Salvacion",
    middle_name: "Pascual",
    sex: "Female",
    birthdate: "1970-10-12",
    purok: "Purok 2 - Rosal",
    address: "Brgy. Tubtubon, Negros Oriental",
    contact: "09901234017",
    guardian: "",
    guardian_contact: "",
    philhealth: "PH-0017-2026",
    consent_sms: true,
    notes: ""
  }
];

// ─────────────────────────────────────────────
//  SERVICE RECORDS
// ─────────────────────────────────────────────
window.MOCK_SERVICE_RECORDS = [
  {
    id: "SR-001",
    upi: "TUB-2026-00001",
    program_type: "Maternal/Prenatal",
    service_date: "2026-06-15",
    form_reference_no: "MAT-2026-0115",
    findings_notes: "AOG 28 weeks. BP 110/70. FHT 140 bpm. Weight 58kg. No complaints.",
    recorded_by: "Nurse Reyes, RNM",
    verified_by: "Dr. Santos",
    created_at: "2026-06-15T09:30:00"
  },
  {
    id: "SR-002",
    upi: "TUB-2026-00001",
    program_type: "Laboratory Referral",
    service_date: "2026-06-20",
    form_reference_no: "LAB-2026-0220",
    findings_notes: "CBC, Urinalysis ordered. Referral to district hospital.",
    recorded_by: "BHW Lim",
    verified_by: "Nurse Reyes, RNM",
    created_at: "2026-06-20T10:00:00"
  },
  {
    id: "SR-003",
    upi: "TUB-2026-00001",
    program_type: "Family Planning",
    service_date: "2026-01-10",
    form_reference_no: "FP-2026-0110",
    findings_notes: "FP counseling done. Method: DMPA injectable. Next visit in 3 months.",
    recorded_by: "Nurse Reyes, RNM",
    verified_by: "Dr. Santos",
    created_at: "2026-01-10T14:00:00"
  },
  {
    id: "SR-004",
    upi: "TUB-2026-00002",
    program_type: "General Consultation",
    service_date: "2026-07-01",
    form_reference_no: "GC-2026-0701",
    findings_notes: "C/C: Cough and colds for 3 days. Prescribed Amoxicillin 500mg TID x 5 days.",
    recorded_by: "BHW Lim",
    verified_by: "Nurse Reyes, RNM",
    created_at: "2026-07-01T11:00:00"
  },
  {
    id: "SR-005",
    upi: "TUB-2026-00010",
    program_type: "Immunization",
    service_date: "2026-08-10",
    form_reference_no: "IMM-2026-0810",
    findings_notes: "DPT-HepB-Hib 3rd dose given. No adverse reaction. Weight 10.2kg.",
    recorded_by: "BHW Cruz",
    verified_by: "Nurse Reyes, RNM",
    created_at: "2026-08-10T08:30:00"
  },
  {
    id: "SR-006",
    upi: "TUB-2026-00010",
    program_type: "Child Health-ECCD",
    service_date: "2026-08-15",
    form_reference_no: "ECCD-2026-0815",
    findings_notes: "Growth monitoring: Normal. Developmental milestone assessment: passed.",
    recorded_by: "BHW Cruz",
    verified_by: "Nurse Reyes, RNM",
    created_at: "2026-08-15T09:00:00"
  },
  {
    id: "SR-007",
    upi: "TUB-2026-00003",
    program_type: "Maternal/Prenatal",
    service_date: "2026-09-01",
    form_reference_no: "MAT-2026-0901",
    findings_notes: "AOG 32 weeks. BP 120/80. FHT 138 bpm. Edema noted on feet.",
    recorded_by: "Nurse Reyes, RNM",
    verified_by: "Dr. Santos",
    created_at: "2026-09-01T10:00:00"
  },
  {
    id: "SR-008",
    upi: "TUB-2026-00017",
    program_type: "Family Planning",
    service_date: "2026-09-10",
    form_reference_no: "FP-2026-0910",
    findings_notes: "FP follow-up. Method: IUD. No complaints. Scheduled next check in 1 year.",
    recorded_by: "Nurse Reyes, RNM",
    verified_by: "Dr. Santos",
    created_at: "2026-09-10T13:00:00"
  }
];

// ─────────────────────────────────────────────
//  DOCUMENT REGISTRY
// ─────────────────────────────────────────────
window.MOCK_DOCUMENTS = [
  {
    id: "DOC-001",
    title: "Maternal Form 1 - Dela Cruz, Juana",
    document_type: "Maternal/Prenatal",
    upi: "TUB-2026-00001",
    patient_name: "DELA CRUZ, Juana",
    status: "Scanned/Attached",
    cabinet_location: "Cabinet A",
    box_location: "Box 1",
    folder_location: "Folder MAT-01",
    file_path: "/uploads/hashed/a1b2c3d4_mat_001.pdf",
    created_at: "2026-06-15T09:30:00",
    updated_at: "2026-06-16T14:00:00",
    notes: ""
  },
  {
    id: "DOC-002",
    title: "FP Form 1 - Dela Cruz, Juana",
    document_type: "Family Planning",
    upi: "TUB-2026-00001",
    patient_name: "DELA CRUZ, Juana",
    status: "Filed",
    cabinet_location: "Cabinet A",
    box_location: "Box 2",
    folder_location: "Folder FP-03",
    file_path: "",
    created_at: "2026-01-10T14:00:00",
    updated_at: "2026-01-10T15:00:00",
    notes: ""
  },
  {
    id: "DOC-003",
    title: "Immunization Card - Mendoza, Ligaya",
    document_type: "Immunization",
    upi: "TUB-2026-00010",
    patient_name: "MENDOZA, Ligaya",
    status: "Pending Digitization",
    cabinet_location: "Cabinet B",
    box_location: "Box 3",
    folder_location: "Folder IMM-05",
    file_path: "",
    created_at: "2026-08-10T08:30:00",
    updated_at: "2026-08-10T09:00:00",
    notes: ""
  },
  {
    id: "DOC-004",
    title: "ECCD Form - Mendoza, Ligaya",
    document_type: "Child Health-ECCD",
    upi: "TUB-2026-00010",
    patient_name: "MENDOZA, Ligaya",
    status: "Filed",
    cabinet_location: "Cabinet B",
    box_location: "Box 3",
    folder_location: "Folder ECCD-02",
    file_path: "",
    created_at: "2026-08-15T09:00:00",
    updated_at: "2026-08-15T10:00:00",
    notes: ""
  },
  {
    id: "DOC-005",
    title: "Lab Referral Slip - Dela Cruz, Juana",
    document_type: "Laboratory Referral",
    upi: "TUB-2026-00001",
    patient_name: "DELA CRUZ, Juana",
    status: "Unavailable/Archived",
    cabinet_location: "Cabinet C",
    box_location: "Box 1",
    folder_location: "Folder LAB-01",
    file_path: "",
    created_at: "2026-06-20T10:00:00",
    updated_at: "2026-07-01T00:00:00",
    notes: "Lost during filing"
  },
  {
    id: "DOC-006",
    title: "FP Form 1 - Hernandez, Salvacion",
    document_type: "Family Planning",
    upi: "TUB-2026-00017",
    patient_name: "HERNANDEZ, Salvacion",
    status: "Scanned/Attached",
    cabinet_location: "Cabinet A",
    box_location: "Box 2",
    folder_location: "Folder FP-03",
    file_path: "/uploads/hashed/e5f6g7h8_fp_017.pdf",
    created_at: "2026-09-10T13:00:00",
    updated_at: "2026-09-11T09:00:00",
    notes: ""
  },
  {
    id: "DOC-007",
    title: "General Consultation - Reyes, Maria",
    document_type: "General Consultation",
    upi: "TUB-2026-00002",
    patient_name: "REYES, Maria",
    status: "Pending Digitization",
    cabinet_location: "Cabinet C",
    box_location: "Box 2",
    folder_location: "Folder GC-04",
    file_path: "",
    created_at: "2026-07-01T11:00:00",
    updated_at: "2026-07-01T12:00:00",
    notes: ""
  }
];

// ─────────────────────────────────────────────
//  INVENTORY - ITEMS
// ─────────────────────────────────────────────
window.MOCK_INVENTORY_ITEMS = [
  {
    id: "ITEM-001",
    name: "Amoxicillin 500mg",
    unit: "capsule",
    category: "Medicine",
    minimum_stock_threshold: 100
  },
  {
    id: "ITEM-002",
    name: "Paracetamol 500mg",
    unit: "tablet",
    category: "Medicine",
    minimum_stock_threshold: 200
  },
  {
    id: "ITEM-003",
    name: "ORS (Oral Rehydration Salts)",
    unit: "sachet",
    category: "Medicine",
    minimum_stock_threshold: 50
  },
  {
    id: "ITEM-004",
    name: "Iron with Folic Acid",
    unit: "tablet",
    category: "Medicine",
    minimum_stock_threshold: 150
  },
  {
    id: "ITEM-005",
    name: "Syringe 5mL",
    unit: "pc",
    category: "Supply",
    minimum_stock_threshold: 30
  },
  {
    id: "ITEM-006",
    name: "Cotrimoxazole 480mg",
    unit: "tablet",
    category: "Medicine",
    minimum_stock_threshold: 80
  },
  {
    id: "ITEM-007",
    name: "Vitamin A 200,000 IU",
    unit: "capsule",
    category: "Medicine",
    minimum_stock_threshold: 60
  },
  {
    id: "ITEM-008",
    name: "Digital BP Apparatus",
    unit: "unit",
    category: "Equipment",
    minimum_stock_threshold: 1
  }
];

// ─────────────────────────────────────────────
//  INVENTORY - BATCHES (FEFO basis)
// ─────────────────────────────────────────────
window.MOCK_INVENTORY_BATCHES = [
  // Amoxicillin 500mg
  { id: "BATCH-001", item_id: "ITEM-001", batch_no: "AMX-2025-001", quantity: 80, expiry_date: "2026-11-30", date_received: "2025-11-01", supplier: "DOH PhilHealth Supply" },
  { id: "BATCH-002", item_id: "ITEM-001", batch_no: "AMX-2026-002", quantity: 120, expiry_date: "2027-06-30", date_received: "2026-01-15", supplier: "DOH PhilHealth Supply" },
  // Paracetamol
  { id: "BATCH-003", item_id: "ITEM-002", batch_no: "PAR-2025-001", quantity: 350, expiry_date: "2027-03-31", date_received: "2025-09-01", supplier: "Generics Pharmacy" },
  // ORS - low stock!
  { id: "BATCH-004", item_id: "ITEM-003", batch_no: "ORS-2026-001", quantity: 25, expiry_date: "2027-12-31", date_received: "2026-03-01", supplier: "DOH Supply" },
  // Iron Folic
  { id: "BATCH-005", item_id: "ITEM-004", batch_no: "IFE-2025-001", quantity: 40, expiry_date: "2026-10-31", date_received: "2025-10-15", supplier: "DOH PhilHealth Supply" },
  { id: "BATCH-006", item_id: "ITEM-004", batch_no: "IFE-2026-002", quantity: 200, expiry_date: "2027-10-31", date_received: "2026-04-01", supplier: "DOH PhilHealth Supply" },
  // Syringe - low stock!
  { id: "BATCH-007", item_id: "ITEM-005", batch_no: "SYR-2026-001", quantity: 20, expiry_date: "2028-01-01", date_received: "2026-02-01", supplier: "Medical Supplies Co." },
  // Cotrimoxazole
  { id: "BATCH-008", item_id: "ITEM-006", batch_no: "CTX-2026-001", quantity: 150, expiry_date: "2027-08-31", date_received: "2026-05-01", supplier: "DOH Supply" },
  // Vitamin A
  { id: "BATCH-009", item_id: "ITEM-007", batch_no: "VTA-2026-001", quantity: 90, expiry_date: "2026-11-01", date_received: "2026-06-01", supplier: "DOH Supply" },
  // BP Apparatus (non-expiring equipment)
  { id: "BATCH-010", item_id: "ITEM-008", batch_no: "EQP-2026-001", quantity: 2, expiry_date: null, date_received: "2026-05-15", supplier: "MHO Supply" }
];

// ─────────────────────────────────────────────
//  INVENTORY - LEDGER
// ─────────────────────────────────────────────
window.MOCK_INVENTORY_LEDGER = [
  { id: "LED-001", item_id: "ITEM-001", batch_id: "BATCH-001", move_type: "Stock-In", quantity: 200, date: "2025-11-01", batch_no: "AMX-2025-001", reason: "", recorded_by: "R. Cruz (Inventory)", notes: "Initial stock" },
  { id: "LED-002", item_id: "ITEM-001", batch_id: "BATCH-001", move_type: "Stock-Out", quantity: 120, date: "2026-03-15", batch_no: "AMX-2025-001", reason: "", recorded_by: "BHW Lim", notes: "Dispensed to patients" },
  { id: "LED-003", item_id: "ITEM-002", batch_id: "BATCH-003", move_type: "Stock-In", quantity: 350, date: "2025-09-01", batch_no: "PAR-2025-001", reason: "", recorded_by: "R. Cruz (Inventory)", notes: "" },
  { id: "LED-004", item_id: "ITEM-003", batch_id: "BATCH-004", move_type: "Stock-In", quantity: 50, date: "2026-03-01", batch_no: "ORS-2026-001", reason: "", recorded_by: "R. Cruz (Inventory)", notes: "" },
  { id: "LED-005", item_id: "ITEM-003", batch_id: "BATCH-004", move_type: "Stock-Out", quantity: 25, date: "2026-06-15", batch_no: "ORS-2026-001", reason: "", recorded_by: "BHW Cruz", notes: "Dispensed for diarrhea cases" },
  { id: "LED-006", item_id: "ITEM-005", batch_id: "BATCH-007", move_type: "Stock-In", quantity: 50, date: "2026-02-01", batch_no: "SYR-2026-001", reason: "", recorded_by: "R. Cruz (Inventory)", notes: "" },
  { id: "LED-007", item_id: "ITEM-005", batch_id: "BATCH-007", move_type: "Stock-Out", quantity: 30, date: "2026-08-10", batch_no: "SYR-2026-001", reason: "", recorded_by: "BHW Cruz", notes: "Immunization day dispensing" },
  { id: "LED-008", item_id: "ITEM-004", batch_id: "BATCH-005", move_type: "Disposal/Expired-Removal", quantity: 10, date: "2026-09-01", batch_no: "IFE-2025-001", reason: "Damaged packaging", recorded_by: "R. Cruz (Inventory)", notes: "Recorded disposal" }
];

// ─────────────────────────────────────────────
//  APPOINTMENTS
// ─────────────────────────────────────────────
window.MOCK_APPOINTMENTS = [
  { id: "APT-001", upi: "TUB-2026-00001", patient_name: "DELA CRUZ, Juana", program_type: "Maternal/Prenatal", scheduled_date: "2026-10-01", scheduled_time: "08:00", status: "Scheduled", reminder_sent: 0, notes: "4th prenatal visit" },
  { id: "APT-002", upi: "TUB-2026-00002", patient_name: "REYES, Maria", program_type: "General Consultation", scheduled_date: "2026-10-01", scheduled_time: "09:00", status: "Scheduled", reminder_sent: 1, notes: "Follow-up checkup" },
  { id: "APT-003", upi: "TUB-2026-00003", patient_name: "SANTOS, Ana", program_type: "Maternal/Prenatal", scheduled_date: "2026-10-05", scheduled_time: "08:30", status: "Scheduled", reminder_sent: 0, notes: "" },
  { id: "APT-004", upi: "TUB-2026-00010", patient_name: "MENDOZA, Ligaya", program_type: "Immunization", scheduled_date: "2026-10-08", scheduled_time: "07:30", status: "Scheduled", reminder_sent: 1, notes: "Measles vaccine" },
  { id: "APT-005", upi: "TUB-2026-00001", patient_name: "DELA CRUZ, Juana", program_type: "Laboratory Referral", scheduled_date: "2026-09-15", scheduled_time: "10:00", status: "Completed", reminder_sent: 1, notes: "CBC results checked" },
  { id: "APT-006", upi: "TUB-2026-00004", patient_name: "GARCIA, Lourdes", program_type: "Family Planning", scheduled_date: "2026-09-20", scheduled_time: "14:00", status: "No-Show", reminder_sent: 1, notes: "DMPA injection follow-up" },
  { id: "APT-007", upi: "TUB-2026-00005", patient_name: "MARTINEZ, Rosario", program_type: "General Consultation", scheduled_date: "2026-09-25", scheduled_time: "09:30", status: "Cancelled", reminder_sent: 0, notes: "Patient called to cancel" },
  { id: "APT-008", upi: "TUB-2026-00017", patient_name: "HERNANDEZ, Salvacion", program_type: "Family Planning", scheduled_date: "2026-10-12", scheduled_time: "13:00", status: "Scheduled", reminder_sent: 0, notes: "IUD follow-up (annual)" },
  { id: "APT-009", upi: "TUB-2026-00007", patient_name: "FLORES, Teresita", program_type: "Maternal/Prenatal", scheduled_date: "2026-10-15", scheduled_time: "08:00", status: "Scheduled", reminder_sent: 0, notes: "" },
  { id: "APT-010", upi: "TUB-2026-00008", patient_name: "RAMOS, Natividad", program_type: "General Consultation", scheduled_date: "2026-10-18", scheduled_time: "10:30", status: "Scheduled", reminder_sent: 0, notes: "" }
];

// ─────────────────────────────────────────────
//  SMS LOGS
// ─────────────────────────────────────────────
window.MOCK_SMS_LOGS = [
  {
    id: "SMS-001",
    upi: "TUB-2026-00001",
    recipient_name: "DELA CRUZ, Juana",
    recipient_number: "09171234001",
    message: "Reminder: You have a scheduled visit on 2026-09-15 at Tubtubon Health Center. Please come on time. -",
    template_used: "appointment_reminder",
    gateway_used: "Semaphore Mock",
    delivery_status: "Sent",
    sent_at: "2026-09-14T10:00:00",
    consent_verified: true
  },
  {
    id: "SMS-002",
    upi: "TUB-2026-00010",
    recipient_name: "MENDOZA, Fe (Guardian)",
    recipient_number: "09781234011",
    message: "Reminder: Ligaya Mendoza has a scheduled Immunization visit on 2026-10-08 at Tubtubon Health Center. -",
    template_used: "appointment_reminder",
    gateway_used: "Semaphore Mock",
    delivery_status: "Sent",
    sent_at: "2026-10-07T09:00:00",
    consent_verified: true
  },
  {
    id: "SMS-003",
    upi: "TUB-2026-00002",
    recipient_name: "REYES, Maria",
    recipient_number: "09181234002",
    message: "Reminder: You have a scheduled visit on 2026-10-01 at Tubtubon Health Center. Please come on time. -",
    template_used: "appointment_reminder",
    gateway_used: "Semaphore Mock",
    delivery_status: "Sent",
    sent_at: "2026-09-30T14:00:00",
    consent_verified: true
  }
];

// ─────────────────────────────────────────────
//  OCR DRAFTS (Rejected)
// ─────────────────────────────────────────────
window.MOCK_OCR_REJECTED = [
  {
    id: "OCR-REJ-001",
    upload_filename: "scan_fp_form_old.jpg",
    uploaded_at: "2026-09-01T11:00:00",
    rejected_at: "2026-09-01T11:05:00",
    rejected_by: "BHW Lim",
    reject_reason: "Wrong document scanned",
    ocr_raw: "FORM 1 FP ... unreadable partial text ..."
  }
];

// ─────────────────────────────────────────────
//  AUDIT LOG
// ─────────────────────────────────────────────
window.MOCK_AUDIT_LOGS = [
  { id: "AUD-001", actor: "Admin Cruz", action: "LOGIN", description: "User logged in as Administrator", ip: "192.168.1.10", record_ref: "-", timestamp: "2026-09-27T07:30:00" },
  { id: "AUD-002", actor: "Nurse Reyes", action: "CREATE", description: "Created service record SR-007 for TUB-2026-00003", ip: "192.168.1.11", record_ref: "SR-007", timestamp: "2026-09-01T10:05:00" },
  { id: "AUD-003", actor: "BHW Lim", action: "CREATE", description: "Created service record SR-004 for TUB-2026-00002", ip: "192.168.1.12", record_ref: "SR-004", timestamp: "2026-07-01T11:10:00" },
  { id: "AUD-004", actor: "Inventory Cruz", action: "STOCK_IN", description: "Stock In 200 pcs Amoxicillin 500mg batch AMX-2025-001", ip: "192.168.1.13", record_ref: "BATCH-001", timestamp: "2025-11-01T08:00:00" },
  { id: "AUD-005", actor: "Admin Cruz", action: "DOC_STATUS", description: "Document DOC-001 status changed to Scanned/Attached", ip: "192.168.1.10", record_ref: "DOC-001", timestamp: "2026-06-16T14:00:00" },
  { id: "AUD-006", actor: "BHW Cruz", action: "SMS_SEND", description: "SMS reminder sent to DELA CRUZ, Juana (09171234001)", ip: "192.168.1.14", record_ref: "SMS-001", timestamp: "2026-09-14T10:00:00" },
  { id: "AUD-007", actor: "BHW Cruz", action: "STOCK_OUT", description: "Stock Out 30 pcs Syringe 5mL batch SYR-2026-001", ip: "192.168.1.14", record_ref: "LED-007", timestamp: "2026-08-10T08:00:00" },
  { id: "AUD-008", actor: "Inventory Cruz", action: "DISPOSAL", description: "Disposal 10 tabs Iron Folic Acid - Damaged packaging", ip: "192.168.1.13", record_ref: "LED-008", timestamp: "2026-09-01T09:00:00" },
  { id: "AUD-009", actor: "Nurse Reyes", action: "OCR_REJECT", description: "OCR draft rejected - Wrong document", ip: "192.168.1.11", record_ref: "OCR-REJ-001", timestamp: "2026-09-01T11:05:00" },
  { id: "AUD-010", actor: "Admin Cruz", action: "BACKUP", description: "Backup export initiated (simulated)", ip: "192.168.1.10", record_ref: "-", timestamp: "2026-09-27T07:35:00" }
];

// ─────────────────────────────────────────────
//  SMS TEMPLATES (privacy-safe)
// ─────────────────────────────────────────────
window.SMS_TEMPLATES = [
  {
    id: "TPL-001",
    name: "appointment_reminder",
    label: "Appointment Reminder",
    body: "Reminder: You have a scheduled visit on [date] at Tubtubon Health Center. Please come on time."
  },
  {
    id: "TPL-002",
    name: "appointment_cancelled",
    label: "Appointment Cancelled",
    body: "Your appointment on [date] at Tubtubon Health Center has been cancelled. Please call us to reschedule."
  },
  {
    id: "TPL-003",
    name: "health_reminder",
    label: "General Health Reminder",
    body: "Health reminder from Tubtubon Health Center. Please visit us for your scheduled check-up."
  },
  {
    id: "TPL-004",
    name: "immunization_due",
    label: "Immunization Due",
    body: "Reminder: An immunization schedule is due on [date] at Tubtubon Health Center. Please bring the immunization card."
  }
];

// ─────────────────────────────────────────────
//  ROLES & MENU CONFIG
// ─────────────────────────────────────────────
window.ROLES = {
  Administrator: {
    label: "Administrator",
    icon: "shield-check",
    color: "#0E4D2B",
    menu: ["dashboard", "patients", "service-entry", "documents", "templates", "inventory", "stock-moves", "scheduling", "sms", "ocr", "reports", "users"]
  },
  "Nurse/Midwife": {
    label: "Nurse/Midwife",
    icon: "stethoscope",
    color: "#2456A6",
    menu: ["dashboard", "patients", "service-entry", "documents", "templates", "scheduling", "sms", "ocr", "reports"]
  },
  "Barangay Health Worker": {
    label: "Barangay Health Worker",
    icon: "id-card",
    color: "#1B7A4D",
    menu: ["dashboard", "patients", "service-entry", "documents", "scheduling", "sms"]
  },
  "Inventory/Admin Personnel": {
    label: "Inventory/Admin Personnel",
    icon: "package",
    color: "#8A5A00",
    menu: ["dashboard", "inventory", "stock-moves", "reports"]
  },
  Resident: {
    label: "Resident",
    icon: "circle-user-round",
    color: "#20c997",
    menu: ["resident-portal"]
  }
};

window.MENU_ITEMS = [
  { id: "dashboard",       icon: "layout-dashboard",   label: "Dashboard & Reports" },
  { id: "patients",        icon: "users",              label: "Patient Registry" },
  { id: "service-entry",   icon: "clipboard-plus",     label: "Service Record Entry" },
  { id: "documents",       icon: "folder-open",        label: "Document Registry" },
  { id: "templates",       icon: "printer",            label: "Printable Templates" },
  { id: "inventory",       icon: "boxes",              label: "Inventory Dashboard" },
  { id: "stock-moves",     icon: "arrow-right-left",   label: "Stock Movements" },
  { id: "scheduling",      icon: "calendar",           label: "Scheduling Calendar" },
  { id: "sms",             icon: "messages-square",    label: "SMS Compose & Logs" },
  { id: "ocr",             icon: "scan-text",          label: "OCR / Digitization" },
  { id: "reports",         icon: "chart-column",       label: "Reports & Backup" },
  { id: "users",           icon: "user-cog",           label: "Users & Roles" },
  { id: "resident-portal", icon: "heart-pulse",        label: "My Health Portal" }
];

console.log("[mock-data.js] SYNTHETIC DEMO DATA loaded. All data is fictional.");
