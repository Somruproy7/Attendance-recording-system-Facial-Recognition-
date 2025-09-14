/**
 * Lecturer Portal JavaScript
 * Handles interactive elements in the lecturer dashboard
 */

document.addEventListener('DOMContentLoaded', function() {
    // Toggle sidebar on mobile
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.querySelector('.sidebar');
    
    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('active');
        });
    }
    
    // Auto-hide sidebar when clicking outside on mobile
    document.addEventListener('click', function(event) {
        const isClickInsideSidebar = sidebar.contains(event.target);
        const isClickOnToggle = sidebarToggle && (sidebarToggle === event.target || sidebarToggle.contains(event.target));
        
        if (window.innerWidth <= 992 && !isClickInsideSidebar && !isClickOnToggle && sidebar.classList.contains('active')) {
            sidebar.classList.remove('active');
        }
    });
    
    // Handle session status toggles
    document.querySelectorAll('.session-status-toggle').forEach(toggle => {
        toggle.addEventListener('change', function() {
            const sessionId = this.dataset.sessionId;
            const status = this.checked ? 'active' : 'inactive';
            
            // Show loading state
            const originalHTML = this.outerHTML;
            this.outerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            
            // Send update to server
            fetch('api/update_session_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    session_id: sessionId,
                    status: status,
                    csrf_token: document.querySelector('meta[name="csrf-token"]')?.content
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Session status updated successfully', 'success');
                    // Reload the page to reflect changes
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showToast(data.message || 'Failed to update session status', 'error');
                    // Revert the toggle
                    this.outerHTML = originalHTML;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('An error occurred while updating the session', 'error');
                // Revert the toggle
                this.outerHTML = originalHTML;
            });
        });
    });
    
    // Initialize tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Initialize popovers
    const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    popoverTriggerList.map(function (popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl);
    });
});

// Function to show toast notifications (compatible with the one in footer.php)
function showToast(message, type = 'info') {
    // If using Bootstrap 5 toasts
    if (typeof bootstrap !== 'undefined' && bootstrap.Toast) {
        const toastContainer = document.getElementById('toastContainer');
        const toastId = 'toast-' + Date.now();
        const toastEl = document.createElement('div');
        
        const typeClass = {
            'success': 'bg-success',
            'error': 'bg-danger',
            'warning': 'bg-warning',
            'info': 'bg-info'
        }[type] || 'bg-primary';
        
        toastEl.id = toastId;
        toastEl.className = `toast align-items-center text-white ${typeClass} border-0`;
        toastEl.setAttribute('role', 'alert');
        toastEl.setAttribute('aria-live', 'assertive');
        toastEl.setAttribute('aria-atomic', 'true');
        
        toastEl.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">
                    ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        `;
        
        toastContainer.appendChild(toastEl);
        const toast = new bootstrap.Toast(toastEl);
        toast.show();
        
        // Remove toast after it's hidden
        toastEl.addEventListener('hidden.bs.toast', function () {
            toastEl.remove();
        });
    } 
    // Fallback to simple alert
    else {
        alert(message);
    }
}

// Function to confirm actions
function confirmAction(message = 'Are you sure you want to perform this action?') {
    return confirm(message);
}

// Function to handle form submissions with AJAX
function handleFormSubmit(form, onSuccess, onError) {
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const submitButton = form.querySelector('[type="submit"]');
        const originalButtonText = submitButton.innerHTML;
        submitButton.disabled = true;
        submitButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';
        
        const formData = new FormData(form);
        
        fetch(form.action || window.location.href, {
            method: form.method || 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (typeof onSuccess === 'function') {
                    onSuccess(data);
                } else {
                    showToast(data.message || 'Action completed successfully', 'success');
                    if (data.redirect) {
                        setTimeout(() => {
                            window.location.href = data.redirect;
                        }, 1500);
                    } else if (data.reload) {
                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    }
                }
            } else {
                throw new Error(data.message || 'An error occurred');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            if (typeof onError === 'function') {
                onError(error);
            } else {
                showToast(error.message || 'An error occurred while processing your request', 'error');
            }
        })
        .finally(() => {
            submitButton.disabled = false;
            submitButton.innerHTML = originalButtonText;
        });
    });
}

// Initialize all AJAX forms
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('form[data-ajax="true"]').forEach(form => {
        handleFormSubmit(form);
    });
});
