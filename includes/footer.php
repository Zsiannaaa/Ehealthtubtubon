<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Access denied.');
}

// ============================================================================
// includes/footer.php — Shared Authenticated App Shell Footer & Thin JS Helpers
// Includes sidebar collapse toggle and asynchronous UPI/Name suggest (FR-06).
// ============================================================================
?>
    </main>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  if (window.lucide) {
    lucide.createIcons();
  }

  // Sidebar collapse toggle
  (function() {
    var collapseBtn = document.getElementById('sidebarCollapseBtn');
    var shell = document.getElementById('app-shell');
    if (collapseBtn && shell) {
      if (localStorage.getItem('tubtubon_sidebar_collapsed') === '1') {
        shell.classList.add('sidebar-collapsed');
      }
      collapseBtn.addEventListener('click', function() {
        shell.classList.toggle('sidebar-collapsed');
        localStorage.setItem(
          'tubtubon_sidebar_collapsed',
          shell.classList.contains('sidebar-collapsed') ? '1' : '0'
        );
      });
    }
  })();

  // FR-06: Asynchronous Topbar Patient Lookup without page reload (/public/api/patients_suggest.php)
  (function() {
    var input = document.getElementById('topbar-search');
    var box   = document.getElementById('topbar-suggest-box');
    if (!input || !box) return;

    var timer = null;
    input.addEventListener('input', function() {
      var q = input.value.trim();
      clearTimeout(timer);
      if (q.length < 1) {
        box.classList.add('d-none');
        box.innerHTML = '';
        return;
      }
      timer = setTimeout(function() {
        fetch((window.APP_BASE_URL || '') + '/public/api/patients_suggest.php?q=' + encodeURIComponent(q), {
          headers: { 'Accept': 'application/json' }
        })
        .then(function(res) { return res.json(); })
        .then(function(payload) {
          if (!payload || !payload.success || !Array.isArray(payload.data) || payload.data.length === 0) {
            box.innerHTML = '<div class="p-2 text-muted small">No matching patients found.</div>';
            box.classList.remove('d-none');
            return;
          }
          var html = '';
          payload.data.forEach(function(p) {
            var url = (window.APP_BASE_URL || '') + '/public/patients.php?view_upi=' + encodeURIComponent(p.upi);
            html += '<a class="suggest-item" href="' + url + '">' +
                      '<div class="d-flex justify-content-between align-items-center">' +
                        '<strong>' + escapeHtml(p.last_name + ', ' + p.first_name) + '</strong>' +
                        '<span class="badge bg-success-subtle text-success">' + escapeHtml(p.upi) + '</span>' +
                      '</div>' +
                      '<div class="text-muted" style="font-size:11px;">' +
                        escapeHtml(p.sex + ' • DOB: ' + p.birthdate + ' • ' + p.purok_address) +
                      '</div>' +
                    '</a>';
          });
          box.innerHTML = html;
          box.classList.remove('d-none');
        })
        .catch(function() {
          box.classList.add('d-none');
        });
      }, 180);
    });

    document.addEventListener('click', function(ev) {
      if (!box.contains(ev.target) && ev.target !== input) {
        box.classList.add('d-none');
      }
    });

    function escapeHtml(str) {
      return String(str || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    }
  })();
</script>
</body>
</html>
