/**
 * SBI MK III - Clean JavaScript for the primary admin UI.
 *
 * @version 1.0.85
 *
 * @safeguard This script follows a simple "fetch-all, render-client-side" pattern
 *   and is the current, preferred architecture for this plugin's UI. It intentionally
 *   avoids the complexity of the previous FSM/TypeScript-based system.
 *
 *   DO NOT add complex state management, build steps (TypeScript/Sass), or
 *   progressive loading. All data is fetched in a single AJAX call from
 *   RepositoryManagerMK3 and then sorted/filtered/paginated on the client.
 *   This is by design to keep the codebase simple and maintainable.
 */
(function($) {    'use strict';

    var SBI_MK3 = {

        currentPage: 1,
        totalPages: 1,
        perPage: 15,
        allRepositories: [], // The master list of all repos
        filteredRepositories: [], // The list after search/sort is applied
        currentSort: {
            key: 'name',
            direction: 'asc'
        },

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
            
            // Search input
            $('#sbi-repo-search').on('keyup', function() {
                self.applyFiltersAndSort();
            });
            
            // Sortable headers
            $(document).on('click', '.sbi-sortable', function() {
                var newSortKey = $(this).data('sort');
                if (self.currentSort.key === newSortKey) {
                    self.currentSort.direction = self.currentSort.direction === 'asc' ? 'desc' : 'asc';
                } else {
                    self.currentSort.key = newSortKey;
                    self.currentSort.direction = 'asc';
                }
                self.applyFiltersAndSort();
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
                        self.allRepositories = response.data.rows;
                        self.currentPage = 1;
                        self.applyFiltersAndSort();
                    } else {
                        var errorMsg = response.data.message || 'Unknown error';
                        console.error('[SBI MK3] Error:', errorMsg);
                        self.showError(errorMsg);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[SBI MK3] AJAX error:', error);
                    self.showError('AJAX error: ' + error + ' (Status: ' + status + ')');
                },
                complete: function() {
                    $('#sbi-mk3-loading').hide();
                }
            });
        },

        /**
         * Central function to apply search and sort, then re-render.
         */
        applyFiltersAndSort: function() {
            var self = this;
            var searchTerm = $('#sbi-repo-search').val().trim().toLowerCase();
            
            // 1. Filter by search term
            var filtered = this.allRepositories;
            if (searchTerm) {
                filtered = this.allRepositories.filter(function(repo) {
                    // Partial match on 'name'
                    return repo.name.toLowerCase().includes(searchTerm);
                });
            }

            // 2. Sort the filtered data
            var sortKey = this.currentSort.key;
            var sortDir = this.currentSort.direction;
            
            // Define a priority map for sorting by state
            const statePriority = {
                'installed_active': 1,
                'installed_inactive': 2,
                'available': 3,
                'installing': 4,
                'error': 5,
            };

            filtered.sort(function(a, b) {
                let valA, valB;

                if (sortKey === 'state') {
                    valA = statePriority[a.state] || 99;
                    valB = statePriority[b.state] || 99;
                } else {
                    valA = a[sortKey].toLowerCase();
                    valB = b[sortKey].toLowerCase();
                }

                if (valA < valB) {
                    return sortDir === 'asc' ? -1 : 1;
                }
                if (valA > valB) {
                    return sortDir === 'asc' ? 1 : -1;
                }
                return 0;
            });
            
            this.filteredRepositories = filtered;
            this.currentPage = 1; // Reset to first page after search/sort
            this.updateSortIcons();
            this.renderPage();
        },
        
        /**
         * Update sort icons in table headers
         */
        updateSortIcons: function() {
            $('.sbi-sortable .dashicons').removeClass('dashicons-arrow-up dashicons-arrow-down').addClass('dashicons-sort');
            
            var $activeHeader = $('.sbi-sortable[data-sort="' + this.currentSort.key + '"]');
            var iconClass = this.currentSort.direction === 'asc' ? 'dashicons-arrow-up' : 'dashicons-arrow-down';
            
            $activeHeader.find('.dashicons').removeClass('dashicons-sort').addClass(iconClass);
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
                        $status.text('✓ Switched to ' + organization).css('color', '#46b450');
                        setTimeout(function() {
                            self.loadRepositories();
                        }, 500);
                    } else {
                        alert('Failed to switch organization: ' + (response.data.message || 'Unknown error'));
                        $status.text('').css('color', '#dc3232');
                    }
                },
                error: function(xhr) {
                    alert('AJAX error: ' + xhr.statusText);
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

            if (perPage < 5) perPage = 5;
            if (perPage > 100) perPage = 100;
            $('#sbi-per-page').val(perPage);

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
                        self.perPage = perPage;
                        $status.text('✓ Updated').css('color', '#46b450');
                        self.renderPage();
                    } else {
                        alert('Failed to update: ' + (response.data.message || 'Unknown error'));
                        $status.text('').css('color', '#dc3232');
                    }
                },
                error: function(xhr) {
                    alert('AJAX error: ' + xhr.statusText);
                    $status.text('').css('color', '#dc3232');
                },
                complete: function() {
                    $button.prop('disabled', false).text('Update');
                }
            });
        },

        showError: function(message) {
            $('#sbi-mk3-error-message').text(message);
            $('#sbi-mk3-error').show();
        },

        /**
         * Render current page of repositories (using filteredRepositories)
         */
        renderPage: function() {
            this.totalPages = Math.ceil(this.filteredRepositories.length / this.perPage);
            if (this.currentPage > this.totalPages) {
                this.currentPage = this.totalPages || 1;
            }

            var startIndex = (this.currentPage - 1) * this.perPage;
            var endIndex = startIndex + this.perPage;
            var pageRepos = this.filteredRepositories.slice(startIndex, endIndex);

            var $tbody = $('#sbi-mk3-tbody');
            $tbody.empty();

            if (pageRepos.length === 0) {
                var message = $('#sbi-repo-search').val() ? 'No repositories match your search.' : 'No repositories found.';
                $tbody.append('<tr><td colspan="4" style="text-align: center; padding: 20px;">' + message + '</td></tr>');
                $('#sbi-mk3-pagination-top').hide();
                $('#sbi-mk3-pagination-bottom').hide();
            } else {
                $.each(pageRepos, function(i, row) {
                    $tbody.append(row.html);
                });
                this.renderPaginationControls();
            }

            $('#sbi-mk3-table').show();
        },

        /**
         * Render pagination controls (using filteredRepositories)
         */
        renderPaginationControls: function() {
            var totalItems = this.filteredRepositories.length;
            var startIndex = (this.currentPage - 1) * this.perPage + 1;
            var endIndex = Math.min(this.currentPage * this.perPage, totalItems);

            $('#sbi-mk3-showing-info').text('Showing ' + startIndex + '-' + endIndex + ' of ' + totalItems + ' repositories');

            var html = '';
            if (this.currentPage > 1) {
                html += '<button class="button sbi-page-link" data-page="' + (this.currentPage - 1) + '">« Previous</button> ';
            } else {
                html += '<button class="button" disabled>« Previous</button> ';
            }

            var startPage = Math.max(1, this.currentPage - 2);
            var endPage = Math.min(this.totalPages, this.currentPage + 2);

            if (startPage > 1) {
                html += '<button class="button sbi-page-link" data-page="1">1</button> ';
                if (startPage > 2) html += '<span style="padding: 0 5px;">...</span> ';
            }

            for (var i = startPage; i <= endPage; i++) {
                if (i === this.currentPage) {
                    html += '<button class="button button-primary" disabled>' + i + '</button> ';
                } else {
                    html += '<button class="button sbi-page-link" data-page="' + i + '">' + i + '</button> ';
                }
            }

            if (endPage < this.totalPages) {
                if (endPage < this.totalPages - 1) html += '<span style="padding: 0 5px;">...</span> ';
                html += '<button class="button sbi-page-link" data-page="' + this.totalPages + '">' + this.totalPages + '</button> ';
            }

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

        goToPage: function(page) {
            this.currentPage = parseInt(page);
            this.renderPage();
            $('html, body').animate({
                scrollTop: $('#sbi-mk3-table').offset().top - 100
            }, 300);
        },

        /**
         * Perform an action and then reload the data state.
         */
        performAction: function(action, repo, $button) {
            var self = this;
            var actionMessages = {
                'install': { start: 'Installing...', fail: 'Install' },
                'activate': { start: 'Activating...', fail: 'Activate' },
                'deactivate': { start: 'Deactivating...', fail: 'Deactivate' }
            };
            
            $button.prop('disabled', true).text(actionMessages[action].start);

            $.ajax({
                url: sbiMK3.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sbi_mk3_' + action + '_plugin',
                    nonce: sbiMK3.nonce,
                    repo: repo
                },
                success: function(response) {
                    if (response.success) {
                        self.reloadAllData();
                    } else {
                        alert(action + ' failed: ' + (response.data.message || 'Unknown error'));
                        $button.prop('disabled', false).text(actionMessages[action].fail);
                    }
                },
                error: function(xhr) {
                    alert('AJAX error: ' + xhr.statusText);
                    $button.prop('disabled', false).text(actionMessages[action].fail);
                }
            });
        },

        installPlugin: function(repo, $button) {
            this.performAction('install', repo, $button);
        },
        activatePlugin: function(repo, $button) {
            this.performAction('activate', repo, $button);
        },
        deactivatePlugin: function(repo, $button) {
            this.performAction('deactivate', repo, $button);
        },
        
        /**
         * Reload all repository data and re-apply filters and sort.
         */
        reloadAllData: function() {
            var self = this;
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
                        // Don't reset page, just re-apply filters and render
                        self.applyFiltersAndSort();
                    }
                }
            });
        }
    };

    $(document).ready(function() {
        SBI_MK3.init();
    });

})(jQuery);

