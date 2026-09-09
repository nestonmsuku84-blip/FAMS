/* ============================================
   THIS CODE RUNS WHEN THE PAGE LOADS
   ============================================ */

// "Wait for the page to fully load, then run this code"
document.addEventListener('DOMContentLoaded', function() {

    // Older dashboard templates linked their red Logout menu item directly to
    // the sign-in page. Route those links through the logout endpoint so the
    // server session is actually destroyed.
    document.querySelectorAll('a.text-danger[href$="auth/login.html"]').forEach(function(link) {
        link.href = new URL('../../api/logout.php', window.location.href).href;
    });

    const studentNameElements = document.querySelectorAll('.student-user-name');
    if (studentNameElements.length > 0) {
        fetch('../../api/profile.php')
            .then(function(response) {
                if (!response.ok) throw new Error('Profile unavailable');
                return response.json();
            })
            .then(function(profile) {
                const name = profile.full_name || 'Student Account';
                studentNameElements.forEach(function(element) {
                    element.textContent = name;
                });
            })
            .catch(function() {
                // Keep the neutral fallback when the profile cannot be loaded.
            });
    }
    
    /* ============================================
       FILE UPLOAD - Drag and Drop
       ============================================ */
    
    // Find all upload areas on the page
    const uploadAreas = document.querySelectorAll('.file-upload');
    
    // For each upload area, add functionality
    uploadAreas.forEach(function(area) {
        // Find the hidden file input inside
        const fileInput = area.querySelector('input[type="file"]');
        
        // When you click the upload area, it clicks the file input
        area.addEventListener('click', function() {
            if (fileInput) {
                fileInput.click(); // This opens the file browser
            }
        });
        
        // When you select a file
        if (fileInput) {
            fileInput.addEventListener('change', function(e) {
                const file = this.files[0]; // Get the first file
                if (file) {
                    // Show the file name
                    const text = area.querySelector('p');
                    if (text) {
                        text.textContent = file.name + ' (' + (file.size / 1024).toFixed(2) + ' KB)';
                    }
                    // Change the icon
                    const icon = area.querySelector('.icon');
                    if (icon) {
                        icon.className = 'fas fa-file icon';
                    }
                    // Add a class to show it's uploaded
                    area.classList.add('has-file');
                }
            });
        }
        
        // Drag and drop functionality
        area.addEventListener('dragover', function(e) {
            e.preventDefault(); // Stop default behavior
            area.classList.add('dragover'); // Highlight the area
        });
        
        area.addEventListener('dragleave', function() {
            area.classList.remove('dragover'); // Remove highlight
        });
        
        area.addEventListener('drop', function(e) {
            e.preventDefault(); // Stop default behavior
            area.classList.remove('dragover'); // Remove highlight
            const files = e.dataTransfer.files; // Get dropped files
            if (files.length > 0 && fileInput) {
                fileInput.files = files; // Set the files
                // Show the file name
                const file = files[0];
                const text = area.querySelector('p');
                if (text) {
                    text.textContent = file.name + ' (' + (file.size / 1024).toFixed(2) + ' KB)';
                }
                const icon = area.querySelector('.icon');
                if (icon) {
                    icon.className = 'fas fa-file icon';
                }
                area.classList.add('has-file');
            }
        });
    });
    
    /* ============================================
       TOGGLE PASSWORD VISIBILITY
       ============================================ */
    
    // Find the password toggle button
    const togglePassword = document.querySelector('#togglePassword');
    if (togglePassword) {
        togglePassword.addEventListener('click', function() {
            const passwordInput = document.querySelector('#password');
            if (passwordInput) {
                // If password is hidden, show it. If visible, hide it.
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    this.querySelector('i').className = 'fas fa-eye-slash';
                } else {
                    passwordInput.type = 'password';
                    this.querySelector('i').className = 'fas fa-eye';
                }
            }
        });
    }

    // Student notification bells show the live unread count and open the
    // notification inbox instead of the template's placeholder menu.
    if (window.location.pathname.includes('/pages/student/')) {
        // Complete the remaining student navigation links that originated in
        // the static template. These are relative to every student page.
        document.querySelectorAll('a[href="#"]').forEach(function(link) {
            const label = link.textContent.trim().toLowerCase();
            if (label.includes('profile')) link.href = 'profile.php';
            if (label.includes('upload documents')) link.href = 'application-form.html#supporting-documents';
        });
        const bellIcons = document.querySelectorAll('.fa-bell');
        bellIcons.forEach(function(icon) {
            const link = icon.closest('a');
            if (link) link.href = new URL('notifications.html', window.location.href).href;
        });
        fetch('../../api/notifications.php')
            .then(function(response) { if (!response.ok) throw new Error('Unavailable'); return response.json(); })
            .then(function(data) {
                document.querySelectorAll('.fa-bell').forEach(function(icon) {
                    const badge = icon.parentElement.querySelector('.badge');
                    if (!badge) return;
                    badge.textContent = data.unread_count;
                    badge.classList.toggle('d-none', data.unread_count === 0);
                });
            })
            .catch(function() {
                // The page remains usable if notifications cannot be loaded.
            });
    }

    // HOD and FAMS-officer dashboards use one role-scoped endpoint so their
    // figures and review queues always reflect the same application workflow.
    const pagePath = window.location.pathname;
    if (pagePath.includes('/pages/hod/dashboard.html') || pagePath.includes('/pages/secretary/dashboard.html') || pagePath.includes('/pages/hod/stats.html') || pagePath.includes('/pages/hod/history.html')) {
        const historyMode = pagePath.includes('/pages/hod/history.html') ? '?mode=history' : '';
        fetch('../../api/dashboard-data.php' + historyMode)
            .then(function(response) { if (!response.ok) throw new Error('Unavailable'); return response.json(); })
            .then(function(data) {
                const statuses = data.statuses || {};
                const total = Object.keys(statuses).reduce(function(sum, key) { return sum + Number(statuses[key] || 0); }, 0);
                const pending = pagePath.includes('/pages/secretary/') ? Number(statuses.under_review || 0) : Number(statuses.submitted || 0) + Number(statuses.resubmitted || 0);
                const values = pagePath.includes('/pages/secretary/')
                    ? [total, pending, Number(statuses.approved || 0), Number(data.placements_confirmed || 0)]
                    : [pending, Number(statuses.approved || 0), Number(statuses.rejected || 0), total];
                document.querySelectorAll('.stat-card .number').forEach(function(element, index) {
                    if (values[index] !== undefined) element.textContent = values[index];
                });
                if (pagePath.includes('/pages/hod/stats.html')) {
                    const distribution = [total, Number(statuses.approved || 0), Number(statuses.rejected || 0), pending];
                    document.querySelectorAll('.stat-card .number').forEach(function(element, index) { element.textContent = distribution[index]; });
                }
                const tableBody = document.querySelector('main table tbody');
                const escape = function(value) { const node = document.createElement('span'); node.textContent = value || ''; return node.innerHTML; };
                if (pagePath.includes('/pages/hod/history.html')) {
                    const rows = data.review_history || [];
                    tableBody.innerHTML = rows.map(function(row) { return '<tr><td><strong>' + escape(row.reference_number) + '</strong></td><td>' + escape(row.student_name) + '</td><td><span class="badge bg-secondary text-capitalize">' + escape((row.decision || '').replace(/_/g, ' ')) + '</span></td><td>' + escape(row.completed_at || '—') + '</td><td><span class="badge bg-success">' + escape(row.status || 'completed') + '</span></td></tr>'; }).join('') || '<tr><td colspan="5" class="text-center text-muted py-4">No completed reviews yet.</td></tr>';
                } else if (pagePath.includes('/dashboard.html')) {
                    const rows = (data.recent_applications || []).filter(function(item) { return pagePath.includes('/pages/secretary/') ? item.status === 'under_review' : ['submitted', 'resubmitted'].includes(item.status); }).slice(0, 5);
                    tableBody.innerHTML = rows.map(function(item) {
                        const href = pagePath.includes('/pages/secretary/') ? 'review.html?id=' : 'review.html?id=';
                        if (pagePath.includes('/pages/secretary/')) return '<tr><td><strong>' + escape(item.reference_number) + '</strong></td><td>' + escape(item.student_name) + '</td><td><span class="badge bg-success">Approved</span></td><td><a class="btn btn-sm btn-primary" href="' + href + item.id + '">Review</a></td></tr>';
                        return '<tr><td><strong>' + escape(item.reference_number) + '</strong></td><td>' + escape(item.student_name) + '</td><td>' + escape(item.specialization_name || '—') + '</td><td>' + escape(item.submitted_at || item.created_at) + '</td><td><a class="btn btn-sm btn-primary" href="' + href + item.id + '">Review</a></td></tr>';
                    }).join('') || '<tr><td colspan="5" class="text-center text-muted py-4">No applications require review.</td></tr>';
                }
            })
            .catch(function() { /* Existing page layout remains available on a temporary API failure. */ });
    }

    if (pagePath.includes('/pages/admin/dashboard.html')) {
        Promise.all(['users', 'institutions', 'companies', 'specializations'].map(function(resource) {
            return fetch('../../api/admin-data.php?resource=' + resource).then(function(response) { if (!response.ok) throw new Error('Unavailable'); return response.json(); });
        })).then(function(results) {
            document.querySelectorAll('.stat-card .number').forEach(function(element, index) {
                element.textContent = (results[index].items || []).length;
            });
        }).catch(function() { /* Leave the dashboard usable if the request fails. */ });
    }

    if (pagePath.includes('/pages/hod/review.html') || pagePath.includes('/pages/secretary/review.html')) {
        const applicationId = new URLSearchParams(window.location.search).get('id');
        if (applicationId) {
            fetch('../../api/application-data.php?mode=detail&id=' + encodeURIComponent(applicationId))
                .then(function(response) { if (!response.ok) throw new Error('Application unavailable'); return response.json(); })
                .then(function(data) {
                    const app = data.application;
                    const escape = function(value) { const node = document.createElement('span'); node.textContent = value || ''; return node.innerHTML; };
                    const documents = (data.documents || []).map(function(doc) {
                        return '<li class="list-group-item d-flex justify-content-between align-items-center"><span>' + escape(doc.original_filename) + '</span><a class="btn btn-sm btn-outline-primary" target="_blank" href="../../api/document-download.php?id=' + encodeURIComponent(doc.id) + '">View</a></li>';
                    }).join('') || '<li class="list-group-item text-muted">No documents uploaded.</li>';
                    const panel = document.querySelector('main .row > .col-md-8');
                    if (!panel) return;
                    panel.innerHTML = '<div class="card mb-4"><div class="card-header"><i class="fas fa-user me-2"></i>Student and Application</div><div class="card-body"><div class="row"><div class="col-md-6"><strong>Name</strong><p>' + escape(app.student_name) + '</p></div><div class="col-md-6"><strong>Registration number</strong><p>' + escape(app.registration_number) + '</p></div><div class="col-md-6"><strong>Email</strong><p>' + escape(app.student_email) + '</p></div><div class="col-md-6"><strong>Specialization</strong><p>' + escape(app.specialization_name || '—') + '</p></div></div><p><strong>Skill level:</strong> ' + escape(app.skill_level) + '</p><p><strong>Submitted:</strong> ' + escape(app.submitted_at || app.created_at) + '</p></div></div><div class="card mb-4"><div class="card-header">Supporting Documents</div><ul class="list-group list-group-flush">' + documents + '</ul></div><div class="card"><div class="card-header">Application Content</div><div class="card-body"><h6>Interest Statement</h6><p>' + escape(app.interest_statement) + '</p><h6>Reason for Application</h6><p>' + escape(app.reason_for_application) + '</p><h6>Learning Objectives</h6><p>' + escape(app.expected_learning_objectives) + '</p></div></div>';
                })
                .catch(function() { showToast('Unable to load this application. It may no longer be available for review.', 'danger'); });
        }
    }
    
    /* ============================================
       DEMO LOGIN - Quick login for testing
       ============================================ */
    
    // Find all demo login buttons
    const demoButtons = document.querySelectorAll('.demo-login');
    demoButtons.forEach(function(btn) {
        btn.addEventListener('click', function() {
            // Get the email and password from the button
            const email = this.getAttribute('data-email');
            const password = this.getAttribute('data-password');
            
            // Fill the login form
            const emailInput = document.querySelector('#email');
            const passwordInput = document.querySelector('#password');
            
            if (emailInput) emailInput.value = email;
            if (passwordInput) passwordInput.value = password;
            
            // Show a message
            showToast('Logging in as ' + this.textContent.trim() + '...', 'info');
            
            // Auto submit after 1 second
            setTimeout(function() {
                const form = document.querySelector('#loginForm');
                if (form) {
                    form.submit();
                }
            }, 1000);
        });
    });
    
    /* ============================================
       FORM VALIDATION - Check if form is filled correctly
       ============================================ */
    
    // Find all forms that need validation
    const forms = document.querySelectorAll('.needs-validation');
    forms.forEach(function(form) {
        form.addEventListener('submit', function(e) {
            // Check if the form is valid
            if (!this.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
            } else {
                // Forms connected to the PHP backend must submit normally.
                if (this.action && (this.action.endsWith('.php') || this.action.includes('/api/'))) {
                    this.classList.add('was-validated');
                    return;
                }
                e.preventDefault();
                // If it's the application form, show success modal
                if (this.id === 'applicationForm') {
                    const successModal = new bootstrap.Modal(document.getElementById('successModal'));
                    successModal.show();
                } else {
                    showToast('Form submitted successfully!', 'success');
                    this.reset();
                }
            }
            this.classList.add('was-validated');
        });
    });
    
    /* ============================================
       TOAST NOTIFICATIONS - Popup messages
       ============================================ */
    
    // This function shows a popup message
    window.showToast = function(message, type) {
        // Default type is 'info'
        type = type || 'info';
        
        // Create the toast container if it doesn't exist
        let toastContainer = document.querySelector('#toast-container');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'toast-container';
            toastContainer.className = 'position-fixed bottom-0 end-0 p-3';
            toastContainer.style.zIndex = '9999';
            document.body.appendChild(toastContainer);
        }
        
        // Create the toast
        const toast = document.createElement('div');
        toast.className = 'toast align-items-center text-white bg-' + type + ' border-0';
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'assertive');
        toast.setAttribute('aria-atomic', 'true');
        
        toast.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">${message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;
        
        // Add to the container
        toastContainer.appendChild(toast);
        
        // Show the toast
        const bsToast = new bootstrap.Toast(toast, { autohide: true, delay: 5000 });
        bsToast.show();
        
        // Remove after hiding
        toast.addEventListener('hidden.bs.toast', function() {
            this.remove();
        });
    };
    
    /* ============================================
       AUTO-HIDE ALERTS - Messages disappear after 5 seconds
       ============================================ */
    
    const alerts = document.querySelectorAll('.alert:not(.alert-permanent)');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            const closeBtn = alert.querySelector('.btn-close');
            if (closeBtn) {
                closeBtn.click();
            }
        }, 5000);
    });
    
    /* ============================================
       CONFIRMATION DIALOGS - "Are you sure?"
       ============================================ */
    
    const confirmButtons = document.querySelectorAll('[data-confirm]');
    confirmButtons.forEach(function(button) {
        button.addEventListener('click', function(e) {
            const message = this.getAttribute('data-confirm') || 'Are you sure?';
            if (!confirm(message)) {
                e.preventDefault();
            }
        });
    });
});

// Force the login form to go to dashboard on submit
document.addEventListener('DOMContentLoaded', function() {
    const loginForm = document.querySelector('form[action="dashboard.html"]');
    if (loginForm) {
        loginForm.addEventListener('submit', function(e) {
            e.preventDefault(); // Stop actual form submission (since there's no backend yet)
            window.location.href = 'dashboard.html'; // Redirect to dashboard
        });
    }
});
