/**
 * SBI MK III - Clean JavaScript
 * 
 * Philosophy:
 * - No FSM
 * - No TypeScript
 * - Simple jQuery AJAX
 * - Sequential flow
 * - Single source of truth (server-rendered HTML)
 * 
 * @version 1.0.80
 */

(function($) {
    'use strict';
    
    var SBI_MK3 = {

        currentPage: 1,
        totalPages: 1,
        allRepositories: [],
        perPage: 15,

        /**
         * Initialize
         */
        init: function() {
            console.log('[SBI MK3] Initializing...');

            // Get per page from input
            this.perPage = parseInt($('#sbi-per-page').val()) || 15;

            // Bind events
            this.bindEvents();

            // Load repositories
            this.loadRepositories();
        },
        
        /**
         * Bind event handlers
         */
        bindEvents: function() {
            var self = this;

            // Switch organization button
            $('#sbi-switch-org').on('click', function(e) {
                e.preventDefault();
                self.switchOrganization();
            });

            // Allow Enter key in organization input
            $('#sbi-github-org').on('keypress', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    self.switchOrganization();
                }
            });

            // Update per page button
            $('#sbi-update-per-page').on('click', function(e) {
                e.preventDefault();
                self.updatePerPage();
            });

            // Allow Enter key in per page input
            $('#sbi-per-page').on('keypress', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    self.updatePerPage();
                }
            });

            // Pagination controls (delegated)
            $(document).on('click', '.sbi-page-link', function(e) {
                e.preventDefault();
                var page = $(this).data('page');
                self.goToPage(page);
            });

            // Install button
            $(document).on('click', '.sbi-mk3-install', function(e) {
                e.preventDefault();
                var repo = $(this).data('repo');
                self.installPlugin(repo, $(this));
            });

            // Activate button
            $(document).on('click', '.sbi-mk3-activate', function(e) {
                e.preventDefault();
                var repo = $(this).data('repo');
                self.activatePlugin(repo, $(this));
            });

            // Deactivate button
            $(document).on('click', '.sbi-mk3-deactivate', function(e) {
                e.preventDefault();
                var repo = $(this).data('repo');
                self.deactivatePlugin(repo, $(this));
            });
        },
        
        /**
         * Load repositories via AJAX
         */
        loadRepositories: function() {
            var self = this;

            console.log('[SBI MK3] Loading repositories...');
            console.log('[SBI MK3] AJAX URL:', sbiMK3.ajaxUrl);
            console.log('[SBI MK3] Nonce:', sbiMK3.nonce);

            $('#sbi-mk3-loading').show();
            $('#sbi-mk3-table').hide();
            $('#sbi-mk3-error').hide();

            $.ajax({
                url: sbiMK3.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sbi_mk3_get_repositories',
                    nonce: sbiMK3.nonce
                },
                success: function(response) {
                    console.log('[SBI MK3] AJAX response:', response);

                    if (response.success) {
                        // Store all repositories
                        self.allRepositories = response.data.rows;
                        self.currentPage = 1;

                        // Render first page
                        self.renderPage();
                    } else {
                        var errorMsg = response.data.message || 'Unknown error';
                        console.error('[SBI MK3] Error:', errorMsg);
                        self.showError(errorMsg);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[SBI MK3] AJAX error:', error);
                    console.error('[SBI MK3] XHR:', xhr);
                    self.showError('AJAX error: ' + error + ' (Status: ' + status + ')');
                },
                complete: function() {
                    $('#sbi-mk3-loading').hide();
                }
            });
        },

        /**
         * Switch GitHub organization
         */
        switchOrganization: function() {
            var self = this;
            var organization = $('#sbi-github-org').val().trim();
            var $button = $('#sbi-switch-org');
            var $status = $('#sbi-org-status');

            if (!organization) {
                alert('Please enter an organization name');
                return;
            }

            console.log('[SBI MK3] Switching to organization:', organization);

            // Update UI
            $button.prop('disabled', true).text('Switching...');
            $status.text('').css('color', '#46b450');

            $.ajax({
                url: sbiMK3.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sbi_mk3_switch_organization',
                    nonce: sbiMK3.nonce,
                    organization: organization
                },
                success: function(response) {
                    if (response.success) {
                        console.log('[SBI MK3] Organization switched:', response.data);
                        $status.text('✓ Switched to ' + organization).css('color', '#46b450');

                        // Reload repositories
                        setTimeout(function() {
                            self.loadRepositories();
                        }, 500);
                    } else {
                        alert('Failed to switch organization: ' + (response.data.message || 'Unknown error'));
                        $status.text('').css('color', '#dc3232');
                    }
                },
                error: function(xhr, status, error) {
                    alert('AJAX error: ' + error);
                    $status.text('').css('color', '#dc3232');
                },
                complete: function() {
                    $button.prop('disabled', false).text('Switch Organization');
                }
            });
        },

        /**
         * Update repositories per page
         */
        updatePerPage: function() {
            var self = this;
            var perPage = parseInt($('#sbi-per-page').val()) || 15;
            var $button = $('#sbi-update-per-page');
            var $status = $('#sbi-per-page-status');

            // Validate
            if (perPage < 5) {
                perPage = 5;
                $('#sbi-per-page').val(5);
            } else if (perPage > 100) {
                perPage = 100;
                $('#sbi-per-page').val(100);
            }

            console.log('[SBI MK3] Updating per page to:', perPage);

            $button.prop('disabled', true).text('Updating...');
            $status.text('').css('color', '#46b450');

            $.ajax({
                url: sbiMK3.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sbi_mk3_update_per_page',
                    nonce: sbiMK3.nonce,
                    per_page: perPage
                },
                success: function(response) {
                    if (response.success) {
                        console.log('[SBI MK3] Per page updated:', response.data);
                        self.perPage = perPage;
                        $status.text('✓ Updated').css('color', '#46b450');

                        // Re-render current page
                        self.renderPage();
                    } else {
                        alert('Failed to update: ' + (response.data.message || 'Unknown error'));
                        $status.text('').css('color', '#dc3232');
                    }
                },
                error: function(xhr, status, error) {
                    alert('AJAX error: ' + error);
                    $status.text('').css('color', '#dc3232');
                },
                complete: function() {
                    $button.prop('disabled', false).text('Update');
                }
            });
        },

        /**
         * Show error message
         */
        showError: function(message) {
            $('#sbi-mk3-error-message').text(message);
            $('#sbi-mk3-error').show();
        },

        /**
         * Render current page of repositories
         */
        renderPage: function() {
            var self = this;

            // Calculate pagination
            this.totalPages = Math.ceil(this.allRepositories.length / this.perPage);

            // Ensure current page is valid
            if (this.currentPage > this.totalPages) {
                this.currentPage = this.totalPages || 1;
            }

            // Get repositories for current page
            var startIndex = (this.currentPage - 1) * this.perPage;
            var endIndex = startIndex + this.perPage;
            var pageRepos = this.allRepositories.slice(startIndex, endIndex);

            // Render table
            var $tbody = $('#sbi-mk3-tbody');
            $tbody.empty();

            console.log('[SBI MK3] Rendering page ' + this.currentPage + ' of ' + this.totalPages);

            if (pageRepos.length === 0) {
                $tbody.append('<tr><td colspan="4" style="text-align: center; padding: 20px;">No repositories found</td></tr>');
                $('#sbi-mk3-pagination-top').hide();
                $('#sbi-mk3-pagination-bottom').hide();
            } else {
                $.each(pageRepos, function(i, row) {
                    $tbody.append(row.html);
                });

                // Render pagination controls
                this.renderPaginationControls();
            }

            $('#sbi-mk3-table').show();
            console.log('[SBI MK3] Rendering complete');
        },

        /**
         * Render pagination controls
         */
        renderPaginationControls: function() {
            var startIndex = (this.currentPage - 1) * this.perPage + 1;
            var endIndex = Math.min(this.currentPage * this.perPage, this.allRepositories.length);

            // Info text
            var infoText = 'Showing ' + startIndex + '-' + endIndex + ' of ' + this.allRepositories.length + ' repositories';
            $('#sbi-mk3-showing-info').text(infoText);

            // Pagination buttons
            var html = '';

            // Previous button
            if (this.currentPage > 1) {
                html += '<button class="button sbi-page-link" data-page="' + (this.currentPage - 1) + '">« Previous</button> ';
            } else {
                html += '<button class="button" disabled>« Previous</button> ';
            }

            // Page numbers
            var startPage = Math.max(1, this.currentPage - 2);
            var endPage = Math.min(this.totalPages, this.currentPage + 2);

            if (startPage > 1) {
                html += '<button class="button sbi-page-link" data-page="1">1</button> ';
                if (startPage > 2) {
                    html += '<span style="padding: 0 5px;">...</span> ';
                }
            }

            for (var i = startPage; i <= endPage; i++) {
                if (i === this.currentPage) {
                    html += '<button class="button button-primary" disabled>' + i + '</button> ';
                } else {
                    html += '<button class="button sbi-page-link" data-page="' + i + '">' + i + '</button> ';
                }
            }

            if (endPage < this.totalPages) {
                if (endPage < this.totalPages - 1) {
                    html += '<span style="padding: 0 5px;">...</span> ';
                }
                html += '<button class="button sbi-page-link" data-page="' + this.totalPages + '">' + this.totalPages + '</button> ';
            }

            // Next button
            if (this.currentPage < this.totalPages) {
                html += '<button class="button sbi-page-link" data-page="' + (this.currentPage + 1) + '">Next »</button>';
            } else {
                html += '<button class="button" disabled>Next »</button>';
            }

            $('#sbi-mk3-pagination-controls').html(html);
            $('#sbi-mk3-pagination-controls-bottom').html(html);

            $('#sbi-mk3-pagination-top').show();
            $('#sbi-mk3-pagination-bottom').show();
        },

        /**
         * Go to specific page
         */
        goToPage: function(page) {
            this.currentPage = parseInt(page);
            this.renderPage();

            // Scroll to top of table
            $('html, body').animate({
                scrollTop: $('#sbi-mk3-table').offset().top - 100
            }, 300);
        },
        
        /**
         * Install plugin
         */
        installPlugin: function(repo, $button) {
            var self = this;
            var $row = $button.closest('tr');
            
            console.log('[SBI MK3] Installing:', repo);
            
            // Update UI
            $button.prop('disabled', true).text('Installing...');
            
            $.ajax({
                url: sbiMK3.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sbi_mk3_install_plugin',
                    nonce: sbiMK3.nonce,
                    repo: repo
                },
                success: function(response) {
                    if (response.success) {
                        console.log('[SBI MK3] Install success:', response.data);
                        // Reload the row
                        self.reloadRow($row, repo);
                    } else {
                        alert('Install failed: ' + (response.data.message || 'Unknown error'));
                        $button.prop('disabled', false).text('Install');
                    }
                },
                error: function(xhr, status, error) {
                    alert('AJAX error: ' + error);
                    $button.prop('disabled', false).text('Install');
                }
            });
        },
        
        /**
         * Activate plugin
         */
        activatePlugin: function(repo, $button) {
            var self = this;
            var $row = $button.closest('tr');

            console.log('[SBI MK3] Activating:', repo);

            // Update UI
            $button.prop('disabled', true).text('Activating...');

            $.ajax({
                url: sbiMK3.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sbi_mk3_activate_plugin',
                    nonce: sbiMK3.nonce,
                    repo: repo
                },
                success: function(response) {
                    if (response.success) {
                        console.log('[SBI MK3] Activate success:', response.data);
                        self.reloadRow($row, repo);
                    } else {
                        alert('Activate failed: ' + (response.data.message || 'Unknown error'));
                        $button.prop('disabled', false).text('Activate');
                    }
                },
                error: function(xhr, status, error) {
                    alert('AJAX error: ' + error);
                    $button.prop('disabled', false).text('Activate');
                }
            });
        },

        /**
         * Deactivate plugin
         */
        deactivatePlugin: function(repo, $button) {
            var self = this;
            var $row = $button.closest('tr');

            console.log('[SBI MK3] Deactivating:', repo);

            // Update UI
            $button.prop('disabled', true).text('Deactivating...');

            $.ajax({
                url: sbiMK3.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sbi_mk3_deactivate_plugin',
                    nonce: sbiMK3.nonce,
                    repo: repo
                },
                success: function(response) {
                    if (response.success) {
                        console.log('[SBI MK3] Deactivate success:', response.data);
                        self.reloadRow($row, repo);
                    } else {
                        alert('Deactivate failed: ' + (response.data.message || 'Unknown error'));
                        $button.prop('disabled', false).text('Deactivate');
                    }
                },
                error: function(xhr, status, error) {
                    alert('AJAX error: ' + error);
                    $button.prop('disabled', false).text('Deactivate');
                }
            });
        },

        /**
         * Reload a single row (reload all and stay on current page)
         */
        reloadRow: function($row, repo) {
            var currentPage = this.currentPage;
            var self = this;

            // Reload all repositories but maintain current page
            $.ajax({
                url: sbiMK3.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sbi_mk3_get_repositories',
                    nonce: sbiMK3.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.allRepositories = response.data.rows;
                        self.currentPage = currentPage;
                        self.renderPage();
                    }
                }
            });
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        SBI_MK3.init();
    });

})(jQuery);

