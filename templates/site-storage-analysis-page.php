<?php
defined('ABSPATH') || exit;
// phpcs:ignoreFile WordPress.Security.EscapeOutput.OutputNotEscaped -- Template outputs trusted internal admin markup fragments.
?>
<div class="wrap rrze-multisite-manager-admin <?php echo esc_attr($mode_class); ?>">
    <div class="rrze-msm-page-shell">
        <div class="rrze-msm-page-header">
            <div>
                <h1><?php echo esc_html__('Storage Analysis', 'rrze-multisite-manager'); ?></h1>
                <p><?php echo esc_html__('Diagnosis of the storage usage of a single website based on its uploads directory.', 'rrze-multisite-manager'); ?></p>
            </div>
            <div class="rrze-msm-header-controls">
                <?php if (empty($site_storage_analysis_is_site_context) && !empty($site_summary)) { ?>
                    <div class="rrze-msm-site-header-search">
                        <label class="screen-reader-text" for="rrze-msm-site-search"><?php echo esc_html__('Search website', 'rrze-multisite-manager'); ?></label>
                        <input id="rrze-msm-site-search" class="regular-text" type="search" placeholder="<?php echo esc_attr($site_search_placeholder); ?>" autocomplete="off">
                        <div class="rrze-msm-site-search-results" id="rrze-msm-site-search-results"></div>
                    </div>
                <?php } ?>
                <button type="button" class="button button-secondary rrze-msm-mode-toggle" data-next-mode="<?php echo esc_attr($mode_class === 'rrze-msm-mode-dark' ? 'light' : 'dark'); ?>">
                    <?php echo esc_html($mode_toggle_label); ?>
                </button>
            </div>
        </div>

        <?php if (empty($site_summary)) { ?>
            <section class="rrze-msm-detail-search-entry">
                <div class="rrze-msm-detail-search-entry-inner">
                    <label class="screen-reader-text" for="rrze-msm-site-search"><?php echo esc_html__('Search website', 'rrze-multisite-manager'); ?></label>
                    <input id="rrze-msm-site-search" class="regular-text" type="search" placeholder="<?php echo esc_attr($site_search_placeholder); ?>" autocomplete="off">
                    <div class="rrze-msm-site-search-results" id="rrze-msm-site-search-results"></div>
                </div>
            </section>
        <?php } ?>

        <?php if (!empty($site_summary)) { ?>
            <?php
            $baseStatus = is_array($storage_analysis_status['base'] ?? null) ? (array)$storage_analysis_status['base'] : [];
            $orphanStatus = is_array($storage_analysis_status['orphan'] ?? null) ? (array)$storage_analysis_status['orphan'] : [];
            $hasCachedAnalysis = !empty($storage_analysis_status['has_cached_analysis']);
            $baseState = (string)($baseStatus['status'] ?? 'idle');
            $orphanState = (string)($orphanStatus['status'] ?? 'idle');
            $orphanAnalysisComplete = (($storage_analysis['orphan_analysis_state'] ?? '') === 'complete') || $orphanState === 'complete';
            $showBatchHint = empty($auto_start_storage_analysis);
            $storageTab = isset($_GET['storage_tab']) ? sanitize_key((string)wp_unslash($_GET['storage_tab'])) : 'analysis';
            $storageTab = in_array($storageTab, ['analysis', 'debug', 'missing-metadata'], true) ? $storageTab : 'analysis';
            $analysisTabUrl = add_query_arg(['site_id' => (int)$site_id, 'storage_tab' => 'analysis'], $site_storage_analysis_base_url);
            $debugTabUrl = add_query_arg(['site_id' => (int)$site_id, 'storage_tab' => 'debug'], $site_storage_analysis_base_url);
            $missingMetadataTabUrl = add_query_arg(['site_id' => (int)$site_id, 'storage_tab' => 'missing-metadata'], $site_storage_analysis_base_url);
            $usedAttachmentFilesRendered = false;
            $siteStorageMegabytes = (int)round((int)($site_summary['storage']['used_bytes'] ?? 0) / MB_IN_BYTES);
            ?>
            <?php if (!empty($orphan_file_error)) { ?>
                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <div class="notice notice-error inline">
                        <p><?php echo esc_html(rawurldecode((string)$orphan_file_error)); ?></p>
                    </div>
                </section>
            <?php } ?>

            <section class="rrze-msm-widget rrze-msm-widget-span-12 rrze-msm-site-details-hero">
                <header class="rrze-msm-widget-header">
                    <h2><?php echo esc_html((string)($site_summary['name'] ?? '')); ?></h2>
                    <p><a href="<?php echo esc_url((string)($site_summary['url'] ?? '')); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string)($site_summary['url'] ?? '')); ?></a></p>
                </header>
                <div class="rrze-msm-site-details-hero-grid">
                    <div class="rrze-msm-site-details-logo">
                        <?php if (!empty($site_summary['branding']['url'])) { ?>
                            <img src="<?php echo esc_url((string)$site_summary['branding']['url']); ?>" alt="<?php echo esc_attr((string)($site_summary['name'] ?? '')); ?>">
                        <?php } else { ?>
                            <span class="rrze-msm-site-branding-empty"><?php echo esc_html__('No logo', 'rrze-multisite-manager'); ?></span>
                        <?php } ?>
                    </div>
                    <div class="rrze-msm-site-details-meta">
                    <div class="rrze-msm-site-details-meta-item">
                        <strong><?php echo esc_html__('Website details', 'rrze-multisite-manager'); ?></strong>
                        <div class="rrze-msm-site-actions">
                            <?php if (!empty($site_details_url)) { ?>
                                <a class="button button-secondary" href="<?php echo esc_url($site_details_url); ?>"><?php echo esc_html__('Back to website details', 'rrze-multisite-manager'); ?></a>
                            <?php } ?>
                                <?php if (!empty($site_media_library_url)) { ?>
                                    <a class="button button-secondary" href="<?php echo esc_url($site_media_library_url); ?>"><?php echo esc_html__('Website media library', 'rrze-multisite-manager'); ?></a>
                                <?php } ?>
                            </div>
                        </div>
                        <div class="rrze-msm-site-details-meta-item">
                            <strong><?php echo esc_html__('WordPress storage value', 'rrze-multisite-manager'); ?></strong>
                            <div><?php echo esc_html(number_format_i18n($siteStorageMegabytes) . ' MB'); ?></div>
                        </div>
                        <div class="rrze-msm-site-details-meta-item">
                            <strong><?php echo esc_html__('Detected size', 'rrze-multisite-manager'); ?></strong>
                            <div><?php echo esc_html((string)($storage_analysis['actual_label'] ?? '')); ?></div>
                        </div>
                    </div>
                </div>
            </section>

            <nav class="rrze-msm-subtabs" aria-label="<?php echo esc_attr__('Storage analysis sections', 'rrze-multisite-manager'); ?>">
                <a class="rrze-msm-subtab<?php echo $storageTab === 'analysis' ? ' is-active' : ''; ?>" href="<?php echo esc_url($analysisTabUrl); ?>"><?php echo esc_html__('Analysis', 'rrze-multisite-manager'); ?></a>
                <a class="rrze-msm-subtab<?php echo $storageTab === 'debug' ? ' is-active' : ''; ?>" href="<?php echo esc_url($debugTabUrl); ?>"><?php echo esc_html__('Media file details', 'rrze-multisite-manager'); ?></a>
                <a class="rrze-msm-subtab<?php echo $storageTab === 'missing-metadata' ? ' is-active' : ''; ?>" href="<?php echo esc_url($missingMetadataTabUrl); ?>"><?php echo esc_html__('Missing metadata', 'rrze-multisite-manager'); ?></a>
            </nav>

            <?php if ($storageTab === 'debug') { ?>
                <?php require __DIR__ . '/site-storage-attachment-debug.php'; ?>
            <?php } elseif ($storageTab === 'missing-metadata') { ?>
                <?php require __DIR__ . '/site-storage-missing-metadata.php'; ?>
            <?php } else { ?>
            <section class="rrze-msm-widget rrze-msm-widget-span-12">
                <header class="rrze-msm-widget-header">
                    <h2><?php echo esc_html__('Analysis status', 'rrze-multisite-manager'); ?></h2>
                    <?php if ($showBatchHint) { ?>
                        <p><?php echo esc_html__('Large upload directories are analyzed in small browser batches.', 'rrze-multisite-manager'); ?></p>
                    <?php } ?>
                </header>
                <div
                    id="rrze-msm-storage-analysis-runner"
                    class="rrze-msm-storage-analysis-runner"
                    data-site-id="<?php echo esc_attr((string)$site_id); ?>"
                    data-base-status="<?php echo esc_attr($baseState); ?>"
                    data-orphan-status="<?php echo esc_attr($orphanState); ?>"
                    data-auto-start="<?php echo esc_attr(!empty($auto_start_storage_analysis) ? '1' : '0'); ?>">
                    <div class="rrze-msm-storage-analysis-state">
                        <h3><?php echo esc_html__('Base analysis', 'rrze-multisite-manager'); ?></h3>
                        <p id="rrze-msm-storage-analysis-base-message"><?php echo esc_html((string)($baseStatus['message'] ?? __('No base analysis is available yet.', 'rrze-multisite-manager'))); ?></p>
                        <?php if (!empty($baseStatus['finished_at'])) { ?>
                            <p class="description"><?php echo esc_html(sprintf(__('Last completed: %s', 'rrze-multisite-manager'), mysql2date(get_option('date_format') . ' ' . get_option('time_format'), (string)$baseStatus['finished_at'], true))); ?></p>
                        <?php } elseif (!empty($storage_analysis_status['cached_generated_at'])) { ?>
                            <p class="description"><?php echo esc_html(sprintf(__('Last status: %s', 'rrze-multisite-manager'), mysql2date(get_option('date_format') . ' ' . get_option('time_format'), (string)$storage_analysis_status['cached_generated_at'], true))); ?></p>
                        <?php } ?>
                        <p class="rrze-msm-site-actions">
                            <button type="button" class="button button-secondary rrze-msm-start-storage-analysis"><?php echo esc_html($hasCachedAnalysis ? __('Refresh analysis', 'rrze-multisite-manager') : __('Start analysis', 'rrze-multisite-manager')); ?></button>
                        </p>
                    </div>
                    <div class="rrze-msm-storage-analysis-state">
                        <h3><?php echo esc_html__('Orphan check', 'rrze-multisite-manager'); ?></h3>
                        <p id="rrze-msm-storage-analysis-orphan-message"><?php echo esc_html((string)($orphanStatus['message'] ?? __('No orphan check is available yet.', 'rrze-multisite-manager'))); ?></p>
                        <?php if (!empty($orphanStatus['finished_at'])) { ?>
                            <p class="description"><?php echo esc_html(sprintf(__('Last completed: %s', 'rrze-multisite-manager'), mysql2date(get_option('date_format') . ' ' . get_option('time_format'), (string)$orphanStatus['finished_at'], true))); ?></p>
                        <?php } ?>
                        <p class="rrze-msm-site-actions">
                            <button type="button" class="button button-secondary rrze-msm-start-storage-orphan-analysis" <?php disabled(!$hasCachedAnalysis); ?>><?php echo esc_html__('Start orphan check', 'rrze-multisite-manager'); ?></button>
                        </p>
                    </div>
                    <div id="rrze-msm-storage-analysis-feedback" class="rrze-msm-storage-analysis-feedback" hidden></div>
                </div>
            </section>

            <?php if (!empty($storage_analysis['error'])) { ?>
                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <div class="notice notice-error inline">
                        <p><?php echo esc_html((string)$storage_analysis['error']); ?></p>
                    </div>
                </section>
            <?php } elseif (!empty($storage_analysis_ready)) { ?>
                <?php if (!empty($storage_analysis['warnings'])) { ?>
                    <section class="rrze-msm-widget rrze-msm-widget-span-12">
                        <?php foreach ((array)$storage_analysis['warnings'] as $warning_row) { ?>
                            <div class="notice <?php echo esc_attr((string)($warning_row['type'] ?? '') === 'info' ? 'notice-info' : 'notice-warning'); ?> inline">
                                <p><?php echo esc_html((string)($warning_row['message'] ?? '')); ?></p>
                            </div>
                        <?php } ?>
                    </section>
                <?php } ?>

                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Overview', 'rrze-multisite-manager'); ?></h2>
                        <p><?php echo esc_html__('Compares the storage value reported by WordPress with the upload directory that was actually scanned.', 'rrze-multisite-manager'); ?></p>
                    </header>
                    <table class="striped rrze-msm-datatable">
                        <tbody>
                            <?php foreach ((array)($storage_analysis['summary_rows'] ?? []) as $summary_row) { ?>
                                <?php $summary_label = (string)($summary_row['label'] ?? ''); ?>
                                <?php if ($summary_label === 'Potentially orphaned files' || $summary_label === __('Potentially orphaned files', 'rrze-multisite-manager')) { ?>
                                    <?php continue; ?>
                                <?php } ?>
                                <tr>
                                    <th><?php echo esc_html($summary_label); ?></th>
                                    <td><?php echo esc_html((string)($summary_row['value'] ?? '')); ?></td>
                                </tr>
                            <?php } ?>
                            <?php if (is_super_admin()) { ?>
                                <tr>
                                    <th><?php echo esc_html__('Uploads URL', 'rrze-multisite-manager'); ?></th>
                                    <td><code><?php echo esc_html((string)($storage_analysis['upload_baseurl'] ?? '')); ?></code></td>
                                </tr>
                                <tr>
                                    <th><?php echo esc_html__('Uploads directory', 'rrze-multisite-manager'); ?></th>
                                    <td><code><?php echo esc_html((string)($storage_analysis['upload_basedir'] ?? '')); ?></code></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </section>
                <?php if ($storageTab === 'debug') { ?>
                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Attachment debug', 'rrze-multisite-manager'); ?></h2>
                        <p><?php echo esc_html__('Checks a specific media library file and shows how the storage analysis currently classifies it.', 'rrze-multisite-manager'); ?></p>
                    </header>
                    <form method="get" class="rrze-msm-site-actions">
                        <input type="hidden" name="page" value="<?php echo esc_attr(isset($_GET['page']) ? sanitize_key((string)wp_unslash($_GET['page'])) : ''); ?>">
                        <input type="hidden" name="site_id" value="<?php echo esc_attr((string)$site_id); ?>">
                        <label for="rrze-msm-debug-attachment-id"><?php echo esc_html__('Attachment ID', 'rrze-multisite-manager'); ?></label>
                        <input id="rrze-msm-debug-attachment-id" name="debug_attachment_id" type="number" min="1" value="<?php echo esc_attr((string)$debug_attachment_id); ?>">
                        <button type="submit" class="button button-secondary"><?php echo esc_html__('Run debug', 'rrze-multisite-manager'); ?></button>
                    </form>
                    <?php if (!empty($attachment_debug)) { ?>
                        <table class="widefat striped rrze-msm-table">
                            <tbody>
                                <tr><th><?php echo esc_html__('Attachment ID', 'rrze-multisite-manager'); ?></th><td><?php echo esc_html((string)($attachment_debug['attachment_id'] ?? '')); ?></td></tr>
                                <tr><th><?php echo esc_html__('Attachment exists', 'rrze-multisite-manager'); ?></th><td><?php echo esc_html(!empty($attachment_debug['exists']) ? __('Yes', 'rrze-multisite-manager') : __('No', 'rrze-multisite-manager')); ?></td></tr>
                                <tr><th><?php echo esc_html__('Title', 'rrze-multisite-manager'); ?></th><td><?php echo esc_html((string)($attachment_debug['title'] ?? '')); ?></td></tr>
                                <tr><th><?php echo esc_html__('Attached file', 'rrze-multisite-manager'); ?></th><td><code><?php echo esc_html((string)($attachment_debug['attached_file'] ?? '')); ?></code></td></tr>
                                <tr><th><?php echo esc_html__('Normalized path', 'rrze-multisite-manager'); ?></th><td><code><?php echo esc_html((string)($attachment_debug['normalized_path'] ?? '')); ?></code></td></tr>
                                <tr><th><?php echo esc_html__('File URL', 'rrze-multisite-manager'); ?></th><td><?php if (!empty($attachment_debug['file_url'])) { ?><a href="<?php echo esc_url((string)$attachment_debug['file_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string)$attachment_debug['file_url']); ?></a><?php } ?></td></tr>
                                <tr><th><?php echo esc_html__('MIME type', 'rrze-multisite-manager'); ?></th><td><?php echo esc_html((string)($attachment_debug['mime_type'] ?? '')); ?></td></tr>
                                <tr><th><?php echo esc_html__('File exists in uploads', 'rrze-multisite-manager'); ?></th><td><?php echo esc_html(!empty($attachment_debug['file_exists']) ? __('Yes', 'rrze-multisite-manager') : __('No', 'rrze-multisite-manager')); ?></td></tr>
                                <tr><th><?php echo esc_html__('Attachment metadata available', 'rrze-multisite-manager'); ?></th><td><?php echo esc_html(!empty($attachment_debug['has_attachment_metadata']) ? __('Yes', 'rrze-multisite-manager') : __('No', 'rrze-multisite-manager')); ?></td></tr>
                                <tr><th><?php echo esc_html__('Image size variants in metadata', 'rrze-multisite-manager'); ?></th><td class="rrze-msm-col-numeric"><?php echo esc_html((string)($attachment_debug['metadata_size_variants'] ?? 0)); ?></td></tr>
                                <tr><th><?php echo esc_html__('In attachment candidate list', 'rrze-multisite-manager'); ?></th><td><?php echo esc_html(!empty($attachment_debug['in_attachment_candidates']) ? __('Yes', 'rrze-multisite-manager') : __('No', 'rrze-multisite-manager')); ?></td></tr>
                                <tr><th><?php echo esc_html__('In attachment index', 'rrze-multisite-manager'); ?></th><td><?php echo esc_html(!empty($attachment_debug['in_attachment_index']) ? __('Yes', 'rrze-multisite-manager') : __('No', 'rrze-multisite-manager')); ?></td></tr>
                                <tr><th><?php echo esc_html__('In cached used attachments', 'rrze-multisite-manager'); ?></th><td><?php echo esc_html(!empty($attachment_debug['in_cached_used_attachments']) ? __('Yes', 'rrze-multisite-manager') : __('No', 'rrze-multisite-manager')); ?></td></tr>
                                <tr><th><?php echo esc_html__('In cached unused attachments', 'rrze-multisite-manager'); ?></th><td><?php echo esc_html(!empty($attachment_debug['in_cached_unused_attachments']) ? __('Yes', 'rrze-multisite-manager') : __('No', 'rrze-multisite-manager')); ?></td></tr>
                                <tr><th><?php echo esc_html__('Cached orphan analysis state', 'rrze-multisite-manager'); ?></th><td><?php echo esc_html((string)($attachment_debug['cached_orphan_analysis_state'] ?? '')); ?></td></tr>
                                <tr><th><?php echo esc_html__('Cached generated at', 'rrze-multisite-manager'); ?></th><td><?php echo esc_html((string)($attachment_debug['cached_generated_at'] ?? '')); ?></td></tr>
                                <tr><th><?php echo esc_html__('Matches without code', 'rrze-multisite-manager'); ?></th><td class="rrze-msm-col-numeric"><?php echo esc_html((string)count((array)($attachment_debug['matches_without_code'] ?? []))); ?></td></tr>
                                <tr><th><?php echo esc_html__('Matches with code', 'rrze-multisite-manager'); ?></th><td class="rrze-msm-col-numeric"><?php echo esc_html((string)count((array)($attachment_debug['matches_with_code'] ?? []))); ?></td></tr>
                            </tbody>
                        </table>
                        <?php if (!empty($attachment_debug['matches_without_code'])) { ?>
                            <h3><?php echo esc_html__('Detected references without code', 'rrze-multisite-manager'); ?></h3>
                            <table class="widefat striped rrze-msm-table">
                                <thead>
                                    <tr>
                                        <th><?php echo esc_html__('Type', 'rrze-multisite-manager'); ?></th>
                                        <th><?php echo esc_html__('Title', 'rrze-multisite-manager'); ?></th>
                                        <th><?php echo esc_html__('Matches', 'rrze-multisite-manager'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ((array)$attachment_debug['matches_without_code'] as $match_row) { ?>
                                        <tr>
                                            <td><?php echo esc_html((string)($match_row['post_type'] ?? '')); ?></td>
                                            <td><?php if (!empty($match_row['edit_url'])) { ?><a href="<?php echo esc_url((string)$match_row['edit_url']); ?>"><?php echo esc_html((string)($match_row['title'] ?? '')); ?></a><?php } else { echo esc_html((string)($match_row['title'] ?? '')); } ?></td>
                                            <td><?php echo esc_html((string)($match_row['matches_label'] ?? '')); ?></td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        <?php } else { ?>
                            <p><?php echo esc_html__('No references were detected in posts, pages, or their meta fields.', 'rrze-multisite-manager'); ?></p>
                        <?php } ?>
                    <?php } ?>
                </section>
                <?php } ?>

                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Top-level folders in the uploads directory', 'rrze-multisite-manager'); ?></h2>
                        <p><?php echo esc_html__('Shows which top-level folders in the uploads area consume how much storage.', 'rrze-multisite-manager'); ?></p>
                    </header>
                    <?php if (!empty($storage_analysis['top_level_directories'])) { ?>
                        <table class="widefat striped rrze-msm-table">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html__('Folders', 'rrze-multisite-manager'); ?></th>
                                    <th class="rrze-msm-col-numeric"><?php echo esc_html__('Files', 'rrze-multisite-manager'); ?></th>
                                    <th class="rrze-msm-col-numeric"><?php echo esc_html__('Size', 'rrze-multisite-manager'); ?></th>
                                    <th class="rrze-msm-col-numeric"><?php echo esc_html__('Share', 'rrze-multisite-manager'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ((array)$storage_analysis['top_level_directories'] as $directory_row) { ?>
                                    <tr>
                                        <td><code><?php echo esc_html((string)($directory_row['path'] ?? '')); ?></code></td>
                                        <td class="rrze-msm-col-numeric"><?php echo esc_html(number_format_i18n((int)($directory_row['file_count'] ?? 0))); ?></td>
                                        <td class="rrze-msm-col-numeric"><?php echo esc_html((string)($directory_row['size_label'] ?? '')); ?></td>
                                        <td class="rrze-msm-col-numeric"><?php echo esc_html(sprintf(__('%d%%', 'rrze-multisite-manager'), (int)($directory_row['percent'] ?? 0))); ?></td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    <?php } else { ?>
                        <p><?php echo esc_html__('No usable folder data was found in the uploads directory.', 'rrze-multisite-manager'); ?></p>
                    <?php } ?>
                </section>

                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Top 10 storage consumers', 'rrze-multisite-manager'); ?></h2>
                        <p><?php echo esc_html__('Shows the ten largest storage consumers in the upload directory and, if needed, an additional "Other" share.', 'rrze-multisite-manager'); ?></p>
                    </header>
                    <?php echo $top_consumers_pie_chart_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Internal trusted pie chart renderer output. ?>
                </section>

                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Largest files', 'rrze-multisite-manager'); ?></h2>
                        <p><?php echo esc_html__('Files in the uploads folder with the highest storage consumption.', 'rrze-multisite-manager'); ?></p>
                    </header>
                    <?php if (!empty($storage_analysis['largest_files'])) { ?>
                        <div class="rrze-msm-site-table-wrap" data-table-id="largest-files" data-default-per-page="20" data-current-page="1" data-sort-key="size" data-sort-direction="desc">
                            <div class="tablenav top">
                                <div class="alignleft actions">
                                    <label for="rrze-msm-largest-files-per-page"><?php echo esc_html__('Show:', 'rrze-multisite-manager'); ?></label>
                                    <select class="rrze-msm-site-table-per-page" id="rrze-msm-largest-files-per-page">
                                        <option value="20" selected><?php echo esc_html__('Standard (20)', 'rrze-multisite-manager'); ?></option>
                                        <option value="10">10</option>
                                        <option value="30">30</option>
                                        <option value="50">50</option>
                                        <option value="100">100</option>
                                    </select>
                                </div>
                            </div>
                            <table class="widefat striped rrze-msm-table">
                                    <thead>
                                        <tr>
                                            <th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="name" data-sort-direction="asc"><span><?php echo esc_html__('File', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="type" data-sort-direction="asc"><span><?php echo esc_html__('Type', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th class="rrze-msm-col-numeric"><button type="button" class="rrze-msm-site-table-sort" data-sort-key="size" data-sort-direction="desc"><span><?php echo esc_html__('Size', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="modified" data-sort-direction="desc"><span><?php echo esc_html__('Last modified', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th class="rrze-msm-col-actions rrze-msm-col-actions-text"><?php echo esc_html__('Actions', 'rrze-multisite-manager'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ((array)$storage_analysis['largest_files'] as $file_row) { ?>
                                            <tr data-sort-name="<?php echo esc_attr(mb_strtolower((string)($file_row['path'] ?? ''))); ?>" data-sort-type="<?php echo esc_attr(mb_strtolower((string)($file_row['type_label'] ?? ''))); ?>" data-sort-size="<?php echo esc_attr((string)($file_row['size_bytes'] ?? 0)); ?>" data-sort-modified="<?php echo esc_attr((string)($file_row['modified_timestamp'] ?? 0)); ?>">
                                            <td class="rrze-msm-col-actions rrze-msm-col-actions-text">
                                                <?php if (!empty($file_row['media_edit_url'])) { ?>
                                                    <a href="<?php echo esc_url((string)$file_row['media_edit_url']); ?>"><code><?php echo esc_html((string)($file_row['path'] ?? '')); ?></code></a>
                                                <?php } else { ?>
                                                    <code><?php echo esc_html((string)($file_row['path'] ?? '')); ?></code>
                                                <?php } ?>
                                            </td>
                                            <td><?php echo esc_html((string)($file_row['type_label'] ?? '')); ?></td>
                                            <td class="rrze-msm-col-numeric"><?php echo esc_html((string)($file_row['size_label'] ?? '')); ?></td>
                                            <td><?php echo esc_html((string)($file_row['modified_label'] ?? '')); ?></td>
                                            <td>
                                                <div class="rrze-msm-site-actions">
                                                    <?php if (!empty($file_row['media_edit_url'])) { ?>
                                                        <a class="button button-secondary" href="<?php echo esc_url((string)$file_row['media_edit_url']); ?>"><?php echo esc_html__('Media library', 'rrze-multisite-manager'); ?></a>
                                                    <?php } elseif (!empty($file_row['file_url'])) { ?>
                                                        <a class="button button-secondary" href="<?php echo esc_url((string)$file_row['file_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Open file', 'rrze-multisite-manager'); ?></a>
                                                    <?php } ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                            <div class="tablenav bottom">
                                <div class="tablenav-pages rrze-msm-site-table-pagination" aria-label="<?php echo esc_attr__('Pagination', 'rrze-multisite-manager'); ?>"></div>
                            </div>
                        </div>
                    <?php } else { ?>
                        <p><?php echo esc_html__('No files were found.', 'rrze-multisite-manager'); ?></p>
                    <?php } ?>
                </section>

                <?php if ($orphanAnalysisComplete && !empty($storage_analysis['used_attachment_files'])) { ?>
                    <section class="rrze-msm-widget rrze-msm-widget-span-12">
                        <header class="rrze-msm-widget-header">
                            <h2><?php echo esc_html__('Registered in the media library and found through references', 'rrze-multisite-manager'); ?></h2>
                            <p><?php echo esc_html__('These media library files were detected through references in posts, pages, or their meta fields. These files were not inserted normally through the editor, but are referenced either through URLs in text or through additional fields managed by themes or plugins. Even if the media library reports no connection, these files are still referenced by the website.', 'rrze-multisite-manager'); ?></p>
                        </header>
                        <div class="rrze-msm-site-table-wrap" data-table-id="used-attachment-files" data-default-per-page="20" data-current-page="1" data-sort-key="size" data-sort-direction="desc">
                            <table class="widefat striped rrze-msm-table">
                                <thead><tr><th><?php echo esc_html__('File', 'rrze-multisite-manager'); ?></th><th><?php echo esc_html__('Type', 'rrze-multisite-manager'); ?></th><th class="rrze-msm-col-numeric"><?php echo esc_html__('Size', 'rrze-multisite-manager'); ?></th><th><?php echo esc_html__('References', 'rrze-multisite-manager'); ?></th></tr></thead>
                                <tbody>
                                    <?php foreach ((array)$storage_analysis['used_attachment_files'] as $orphan_row) { ?>
                                        <tr data-sort-name="<?php echo esc_attr(mb_strtolower((string)($orphan_row['path'] ?? ''))); ?>" data-sort-type="<?php echo esc_attr(mb_strtolower((string)($orphan_row['type_label'] ?? ''))); ?>" data-sort-size="<?php echo esc_attr((string)($orphan_row['size_bytes'] ?? 0)); ?>">
                                            <td><?php if (!empty($orphan_row['media_edit_url'])) { ?><a href="<?php echo esc_url((string)$orphan_row['media_edit_url']); ?>"><code><?php echo esc_html((string)($orphan_row['path'] ?? '')); ?></code></a><?php } else { ?><code><?php echo esc_html((string)($orphan_row['path'] ?? '')); ?></code><?php } ?></td>
                                            <td><?php echo esc_html((string)($orphan_row['type_label'] ?? '')); ?></td>
                                            <td class="rrze-msm-col-numeric"><?php echo esc_html((string)($orphan_row['size_label'] ?? '')); ?></td>
                                            <td><?php echo esc_html((string)($orphan_row['content_usage_label'] ?? '')); ?></td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                            <div class="tablenav bottom"><div class="tablenav-pages rrze-msm-site-table-pagination" aria-label="<?php echo esc_attr__('Pagination', 'rrze-multisite-manager'); ?>"></div></div>
                        </div>
                    </section>
                    <?php $usedAttachmentFilesRendered = true; ?>
                <?php } ?>

                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <header class="rrze-msm-widget-header">
                        <h2><?php echo esc_html__('Potentially orphaned files', 'rrze-multisite-manager'); ?></h2>
                        <p><?php echo esc_html__('These are files in the uploads folder that are currently not referenced by attachment metadata.', 'rrze-multisite-manager'); ?></p>
                    </header>
                    <p>
                        <strong><?php echo esc_html(number_format_i18n((int)($storage_analysis['combined_flagged_file_count'] ?? 0))); ?></strong>
                        <?php echo esc_html__('Files', 'rrze-multisite-manager'); ?>
                        ,
                        <strong><?php echo esc_html((string)($storage_analysis['combined_flagged_total_label'] ?? '')); ?></strong>
                    </p>
                    <?php if (!empty($storage_analysis['orphan_files_truncated'])) { ?>
                        <div class="notice notice-warning inline">
                            <p><?php echo esc_html__('For performance reasons, the analysis for the detail tables was limited to a larger but still bounded subset of potentially orphaned files.', 'rrze-multisite-manager'); ?></p>
                        </div>
                    <?php } ?>
                    <?php if (!empty($storage_analysis['largest_orphan_files']) || ($orphanAnalysisComplete && !empty($storage_analysis['unused_attachment_files']))) { ?>
                        <?php if ($orphanAnalysisComplete && !empty($storage_analysis['orphan_files_found_in_content'])) { ?>
                            <h3><?php echo esc_html__('Still found through references or code registration', 'rrze-multisite-manager'); ?></h3>
                            <p><?php echo esc_html__('These files are not attachments, but are still referenced in posts, pages, their meta fields, or through register/enqueue calls in active plugins, MU plugins, or the active theme.', 'rrze-multisite-manager'); ?></p>
                            <div class="rrze-msm-site-table-wrap" data-table-id="orphan-files-referenced" data-default-per-page="20" data-current-page="1" data-sort-key="size" data-sort-direction="desc">
                                <div class="tablenav top">
                                    <div class="alignleft actions">
                                        <label for="rrze-msm-orphan-files-referenced-per-page"><?php echo esc_html__('Show:', 'rrze-multisite-manager'); ?></label>
                                        <select class="rrze-msm-site-table-per-page" id="rrze-msm-orphan-files-referenced-per-page">
                                            <option value="20" selected><?php echo esc_html__('Standard (20)', 'rrze-multisite-manager'); ?></option>
                                            <option value="10">10</option>
                                            <option value="30">30</option>
                                            <option value="50">50</option>
                                            <option value="100">100</option>
                                        </select>
                                    </div>
                                </div>
                                <table class="widefat striped rrze-msm-table">
                                    <thead>
                                        <tr>
                                            <th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="name" data-sort-direction="asc"><span><?php echo esc_html__('File', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="type" data-sort-direction="asc"><span><?php echo esc_html__('Type', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th class="rrze-msm-col-numeric"><button type="button" class="rrze-msm-site-table-sort" data-sort-key="size" data-sort-direction="desc"><span><?php echo esc_html__('Size', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th><?php echo esc_html__('References', 'rrze-multisite-manager'); ?></th>
                                            <th class="rrze-msm-col-actions rrze-msm-col-actions-text"><?php echo esc_html__('Actions', 'rrze-multisite-manager'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ((array)$storage_analysis['orphan_files_found_in_content'] as $orphan_row) { ?>
                                            <tr data-sort-name="<?php echo esc_attr(mb_strtolower((string)($orphan_row['path'] ?? ''))); ?>" data-sort-type="<?php echo esc_attr(mb_strtolower((string)($orphan_row['type_label'] ?? ''))); ?>" data-sort-size="<?php echo esc_attr((string)($orphan_row['size_bytes'] ?? 0)); ?>">
                                                <td><code><?php echo esc_html((string)($orphan_row['path'] ?? '')); ?></code></td>
                                                <td><?php echo esc_html((string)($orphan_row['type_label'] ?? '')); ?></td>
                                                <td class="rrze-msm-col-numeric"><?php echo esc_html((string)($orphan_row['size_label'] ?? '')); ?></td>
                                                <td><?php echo esc_html((string)($orphan_row['content_usage_label'] ?? '')); ?></td>
                                                <td class="rrze-msm-col-actions rrze-msm-col-actions-text">
                                                    <div class="rrze-msm-site-actions">
                                                        <?php if (!empty($orphan_row['file_url'])) { ?>
                                                            <a class="button button-secondary" href="<?php echo esc_url((string)$orphan_row['file_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Open file', 'rrze-multisite-manager'); ?></a>
                                                            <a class="button button-secondary" href="<?php echo esc_url('https://www.google.com/search?q=' . rawurlencode('"' . (string)$orphan_row['file_url'] . '"')); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Search on Google', 'rrze-multisite-manager'); ?></a>
                                                        <?php } ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                                <div class="tablenav bottom">
                                    <div class="tablenav-pages rrze-msm-site-table-pagination" aria-label="<?php echo esc_attr__('Pagination', 'rrze-multisite-manager'); ?>"></div>
                                </div>
                            </div>
                        <?php } elseif ($orphanAnalysisComplete && !empty($storage_analysis['largest_orphan_files'])) { ?>
                            <h3><?php echo esc_html__('No references or code registrations found for the analyzed non-attachment files', 'rrze-multisite-manager'); ?></h3>
                            <p><?php echo esc_html__('For the analyzed potentially orphaned files, no references were found in posts, pages, their meta fields, or through register/enqueue calls in active code.', 'rrze-multisite-manager'); ?></p>
                        <?php } elseif (!$orphanAnalysisComplete) { ?>
                            <div class="notice notice-info inline">
                                <p><?php echo esc_html__('The base analysis only collected candidates. Start the orphan check now so references in content, meta fields, and active code are actually verified.', 'rrze-multisite-manager'); ?></p>
                            </div>
                        <?php } ?>

                        <?php if ($orphanAnalysisComplete && !empty($storage_analysis['unused_attachment_files'])) { ?>
                            <h3 id="rrze-msm-unused-attachments"><?php echo esc_html__('Registered in the media library, but not found in use anywhere', 'rrze-multisite-manager'); ?></h3>
                            <p><?php echo esc_html__('These files exist as media library attachments, but no direct references were found in posts, pages, or their meta fields.', 'rrze-multisite-manager'); ?></p>
                            <?php if (!empty($orphan_file_delete_notice)) { ?>
                                <div class="notice notice-success inline">
                                    <p><?php echo esc_html(sprintf(_n('%d file deleted.', '%d files deleted.', (int)($orphan_file_delete_notice['count'] ?? 0), 'rrze-multisite-manager'), (int)($orphan_file_delete_notice['count'] ?? 0))); ?></p>
                                    <?php if (!empty($orphan_file_delete_notice['files']) && is_array($orphan_file_delete_notice['files'])) { ?>
                                        <p><strong><?php echo esc_html__('Deleted files:', 'rrze-multisite-manager'); ?></strong></p>
                                        <ul>
                                            <?php foreach ((array)$orphan_file_delete_notice['files'] as $deleted_file) { ?>
                                                <li><code><?php echo esc_html((string)$deleted_file); ?></code></li>
                                            <?php } ?>
                                        </ul>
                                        <?php if ((int)($orphan_file_delete_notice['count'] ?? 0) > count($orphan_file_delete_notice['files'])) { ?>
                                            <p><?php echo esc_html(sprintf(__('and %d more files.', 'rrze-multisite-manager'), (int)($orphan_file_delete_notice['count'] ?? 0) - count($orphan_file_delete_notice['files']))); ?></p>
                                        <?php } ?>
                                    <?php } ?>
                                </div>
                            <?php } ?>
                            <form method="post" action="<?php echo esc_url((string)$orphan_file_delete_action); ?>">
                                <input type="hidden" name="action" value="rrze_multisite_manager_delete_orphan_file">
                                <input type="hidden" name="site_id" value="<?php echo esc_attr((string)$site_id); ?>">
                                <?php wp_nonce_field('rrze_multisite_manager_delete_orphan_files_' . $site_id); ?>
                                <p class="rrze-msm-site-actions">
                                    <button type="button" class="button button-secondary rrze-msm-toggle-orphan-file-selection" data-select-label="<?php echo esc_attr__('Select all', 'rrze-multisite-manager'); ?>" data-unselect-label="<?php echo esc_attr__('Clear selection', 'rrze-multisite-manager'); ?>"><?php echo esc_html__('Select all', 'rrze-multisite-manager'); ?></button>
                                    <button type="button" class="button button-primary rrze-msm-open-orphan-file-bulk-delete-modal" data-selection-name="attachment_ids[]" data-site-id="<?php echo esc_attr((string)$site_id); ?>" data-delete-nonce="<?php echo esc_attr(wp_create_nonce('rrze_multisite_manager_delete_orphan_files_' . $site_id)); ?>"><?php echo esc_html__('Delete selected files', 'rrze-multisite-manager'); ?></button>
                                </p>
                            <div class="rrze-msm-site-table-wrap" data-table-id="unused-attachment-files" data-default-per-page="20" data-current-page="1" data-sort-key="size" data-sort-direction="desc">
                                <div class="tablenav top">
                                    <div class="alignleft actions">
                                        <label for="rrze-msm-unused-attachment-files-per-page"><?php echo esc_html__('Show:', 'rrze-multisite-manager'); ?></label>
                                        <select class="rrze-msm-site-table-per-page" id="rrze-msm-unused-attachment-files-per-page">
                                            <option value="20" selected><?php echo esc_html__('Standard (20)', 'rrze-multisite-manager'); ?></option>
                                            <option value="10">10</option>
                                            <option value="30">30</option>
                                            <option value="50">50</option>
                                            <option value="100">100</option>
                                        </select>
                                    </div>
                                </div>
                                <table class="widefat striped rrze-msm-table">
                                    <thead>
                                        <tr>
                                            <th><?php echo esc_html__('Selection', 'rrze-multisite-manager'); ?></th>
                                            <th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="name" data-sort-direction="asc"><span><?php echo esc_html__('File', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="type" data-sort-direction="asc"><span><?php echo esc_html__('Type', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th class="rrze-msm-col-numeric"><button type="button" class="rrze-msm-site-table-sort" data-sort-key="size" data-sort-direction="desc"><span><?php echo esc_html__('Size', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="modified" data-sort-direction="desc"><span><?php echo esc_html__('Last modified', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th class="rrze-msm-col-actions rrze-msm-col-actions-text"><?php echo esc_html__('Actions', 'rrze-multisite-manager'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ((array)$storage_analysis['unused_attachment_files'] as $orphan_row) { ?>
                                            <tr data-sort-name="<?php echo esc_attr(mb_strtolower((string)($orphan_row['path'] ?? ''))); ?>" data-sort-type="<?php echo esc_attr(mb_strtolower((string)($orphan_row['type_label'] ?? ''))); ?>" data-sort-size="<?php echo esc_attr((string)($orphan_row['size_bytes'] ?? 0)); ?>" data-sort-modified="<?php echo esc_attr((string)($orphan_row['modified_timestamp'] ?? 0)); ?>">
                                                <td><input type="checkbox" name="attachment_ids[]" value="<?php echo esc_attr((string)($orphan_row['attachment_id'] ?? 0)); ?>"></td>
                                                <td>
                                                    <?php if (!empty($orphan_row['media_edit_url'])) { ?>
                                                        <a href="<?php echo esc_url((string)$orphan_row['media_edit_url']); ?>"><code><?php echo esc_html((string)($orphan_row['path'] ?? '')); ?></code></a>
                                                    <?php } else { ?>
                                                        <code><?php echo esc_html((string)($orphan_row['path'] ?? '')); ?></code>
                                                    <?php } ?>
                                                </td>
                                                <td><?php echo esc_html((string)($orphan_row['type_label'] ?? '')); ?></td>
                                                <td class="rrze-msm-col-numeric"><?php echo esc_html((string)($orphan_row['size_label'] ?? '')); ?></td>
                                                <td><?php echo esc_html((string)($orphan_row['modified_label'] ?? '')); ?></td>
                                                <td class="rrze-msm-col-actions rrze-msm-col-actions-text">
                                                    <div class="rrze-msm-site-actions">
                                                        <?php if (!empty($orphan_row['media_edit_url'])) { ?>
                                                            <a class="button button-secondary" href="<?php echo esc_url((string)$orphan_row['media_edit_url']); ?>"><?php echo esc_html__('Media library', 'rrze-multisite-manager'); ?></a>
                                                        <?php } ?>
                                                        <?php if (!empty($orphan_row['file_url'])) { ?>
                                                            <a class="button button-secondary" href="<?php echo esc_url((string)$orphan_row['file_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Open file', 'rrze-multisite-manager'); ?></a>
                                                            <a class="button button-secondary" href="<?php echo esc_url('https://www.google.com/search?q=' . rawurlencode('"' . (string)$orphan_row['file_url'] . '"')); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Search on Google', 'rrze-multisite-manager'); ?></a>
                                                        <?php } ?>
                                                        <button type="button" class="button button-primary rrze-msm-open-unused-attachment-delete-modal" data-attachment-id="<?php echo esc_attr((string)($orphan_row['attachment_id'] ?? 0)); ?>" data-file-path="<?php echo esc_attr((string)($orphan_row['path'] ?? '')); ?>" data-site-id="<?php echo esc_attr((string)$site_id); ?>" data-delete-nonce="<?php echo esc_attr(wp_create_nonce('rrze_multisite_manager_delete_orphan_files_' . $site_id)); ?>"><?php echo esc_html__('Delete file', 'rrze-multisite-manager'); ?></button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                                <div class="tablenav bottom">
                                    <div class="tablenav-pages rrze-msm-site-table-pagination" aria-label="<?php echo esc_attr__('Pagination', 'rrze-multisite-manager'); ?>"></div>
                                </div>
                            </div>
                            </form>
                        <?php } ?>

                        <?php if ($orphanAnalysisComplete && !empty($storage_analysis['used_attachment_files']) && !$usedAttachmentFilesRendered) { ?>
                            <h3><?php echo esc_html__('Registered in the media library and found through references', 'rrze-multisite-manager'); ?></h3>
                            <p><?php echo esc_html__('These media library files were detected through references in posts, pages, or their meta fields.', 'rrze-multisite-manager'); ?></p>
                            <div class="rrze-msm-site-table-wrap" data-table-id="used-attachment-files" data-default-per-page="20" data-current-page="1" data-sort-key="size" data-sort-direction="desc">
                                <div class="tablenav top">
                                    <div class="alignleft actions">
                                        <label for="rrze-msm-used-attachment-files-per-page"><?php echo esc_html__('Show:', 'rrze-multisite-manager'); ?></label>
                                        <select class="rrze-msm-site-table-per-page" id="rrze-msm-used-attachment-files-per-page">
                                            <option value="20" selected><?php echo esc_html__('Standard (20)', 'rrze-multisite-manager'); ?></option>
                                            <option value="10">10</option>
                                            <option value="30">30</option>
                                            <option value="50">50</option>
                                            <option value="100">100</option>
                                        </select>
                                    </div>
                                </div>
                                <table class="widefat striped rrze-msm-table">
                                    <thead>
                                        <tr>
                                            <th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="name" data-sort-direction="asc"><span><?php echo esc_html__('File', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="type" data-sort-direction="asc"><span><?php echo esc_html__('Type', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th class="rrze-msm-col-numeric"><button type="button" class="rrze-msm-site-table-sort" data-sort-key="size" data-sort-direction="desc"><span><?php echo esc_html__('Size', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th><?php echo esc_html__('References', 'rrze-multisite-manager'); ?></th>
                                            <th class="rrze-msm-col-actions rrze-msm-col-actions-text"><?php echo esc_html__('Actions', 'rrze-multisite-manager'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ((array)$storage_analysis['used_attachment_files'] as $orphan_row) { ?>
                                            <tr data-sort-name="<?php echo esc_attr(mb_strtolower((string)($orphan_row['path'] ?? ''))); ?>" data-sort-type="<?php echo esc_attr(mb_strtolower((string)($orphan_row['type_label'] ?? ''))); ?>" data-sort-size="<?php echo esc_attr((string)($orphan_row['size_bytes'] ?? 0)); ?>">
                                                <td>
                                                    <?php if (!empty($orphan_row['media_edit_url'])) { ?>
                                                        <a href="<?php echo esc_url((string)$orphan_row['media_edit_url']); ?>"><code><?php echo esc_html((string)($orphan_row['path'] ?? '')); ?></code></a>
                                                    <?php } else { ?>
                                                        <code><?php echo esc_html((string)($orphan_row['path'] ?? '')); ?></code>
                                                    <?php } ?>
                                                </td>
                                                <td><?php echo esc_html((string)($orphan_row['type_label'] ?? '')); ?></td>
                                                <td class="rrze-msm-col-numeric"><?php echo esc_html((string)($orphan_row['size_label'] ?? '')); ?></td>
                                                <td><?php echo esc_html((string)($orphan_row['content_usage_label'] ?? '')); ?></td>
                                                <td class="rrze-msm-col-actions rrze-msm-col-actions-text">
                                                    <div class="rrze-msm-site-actions">
                                                        <?php if (!empty($orphan_row['media_edit_url'])) { ?>
                                                            <a class="button button-secondary" href="<?php echo esc_url((string)$orphan_row['media_edit_url']); ?>"><?php echo esc_html__('Media library', 'rrze-multisite-manager'); ?></a>
                                                        <?php } ?>
                                                        <?php if (!empty($orphan_row['file_url'])) { ?>
                                                            <a class="button button-secondary" href="<?php echo esc_url((string)$orphan_row['file_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Open file', 'rrze-multisite-manager'); ?></a>
                                                        <?php } ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                                <div class="tablenav bottom">
                                    <div class="tablenav-pages rrze-msm-site-table-pagination" aria-label="<?php echo esc_attr__('Pagination', 'rrze-multisite-manager'); ?>"></div>
                                </div>
                            </div>
                        <?php } ?>

                        <?php if ($orphanAnalysisComplete && !empty($storage_analysis['orphan_files_without_content_matches'])) { ?>
                            <h3><?php echo esc_html__('Not found in references or active code registration anywhere', 'rrze-multisite-manager'); ?></h3>
                            <p><?php echo esc_html__('These files are not attachments, and no direct references were found in posts, pages, their meta fields, or register/enqueue calls in active code.', 'rrze-multisite-manager'); ?></p>
                            <form method="post" action="<?php echo esc_url((string)$orphan_file_delete_action); ?>">
                                <input type="hidden" name="action" value="rrze_multisite_manager_delete_orphan_file">
                                <input type="hidden" name="site_id" value="<?php echo esc_attr((string)$site_id); ?>">
                                <?php wp_nonce_field('rrze_multisite_manager_delete_orphan_files_' . $site_id); ?>
                                <p class="rrze-msm-site-actions">
                                    <button
                                        type="button"
                                        class="button button-secondary rrze-msm-toggle-orphan-file-selection"
                                        data-select-label="<?php echo esc_attr__('Select all', 'rrze-multisite-manager'); ?>"
                                        data-unselect-label="<?php echo esc_attr__('Clear selection', 'rrze-multisite-manager'); ?>">
                                        <?php echo esc_html__('Select all', 'rrze-multisite-manager'); ?>
                                    </button>
                                    <button type="button" class="button button-primary rrze-msm-open-orphan-file-bulk-delete-modal" data-site-id="<?php echo esc_attr((string)$site_id); ?>" data-delete-nonce="<?php echo esc_attr(wp_create_nonce('rrze_multisite_manager_delete_orphan_files_' . $site_id)); ?>"><?php echo esc_html__('Delete selected files', 'rrze-multisite-manager'); ?></button>
                                </p>
                            <div class="rrze-msm-site-table-wrap" data-table-id="orphan-files-unreferenced" data-default-per-page="20" data-current-page="1" data-sort-key="size" data-sort-direction="desc">
                                <div class="tablenav top">
                                    <div class="alignleft actions">
                                        <label for="rrze-msm-orphan-files-unreferenced-per-page"><?php echo esc_html__('Show:', 'rrze-multisite-manager'); ?></label>
                                        <select class="rrze-msm-site-table-per-page" id="rrze-msm-orphan-files-unreferenced-per-page">
                                            <option value="20" selected><?php echo esc_html__('Standard (20)', 'rrze-multisite-manager'); ?></option>
                                            <option value="10">10</option>
                                            <option value="30">30</option>
                                            <option value="50">50</option>
                                            <option value="100">100</option>
                                        </select>
                                    </div>
                                </div>
                                <table class="widefat striped rrze-msm-table">
                                    <thead>
                                        <tr>
                                            <th><?php echo esc_html__('Selection', 'rrze-multisite-manager'); ?></th>
                                            <th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="name" data-sort-direction="asc"><span><?php echo esc_html__('File', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="type" data-sort-direction="asc"><span><?php echo esc_html__('Type', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th class="rrze-msm-col-numeric"><button type="button" class="rrze-msm-site-table-sort" data-sort-key="size" data-sort-direction="desc"><span><?php echo esc_html__('Size', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="modified" data-sort-direction="desc"><span><?php echo esc_html__('Last modified', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th class="rrze-msm-col-actions rrze-msm-col-actions-text"><?php echo esc_html__('Actions', 'rrze-multisite-manager'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ((array)$storage_analysis['orphan_files_without_content_matches'] as $orphan_row) { ?>
                                            <tr data-sort-name="<?php echo esc_attr(mb_strtolower((string)($orphan_row['path'] ?? ''))); ?>" data-sort-type="<?php echo esc_attr(mb_strtolower((string)($orphan_row['type_label'] ?? ''))); ?>" data-sort-size="<?php echo esc_attr((string)($orphan_row['size_bytes'] ?? 0)); ?>" data-sort-modified="<?php echo esc_attr((string)($orphan_row['modified_timestamp'] ?? 0)); ?>">
                                                <td><input type="checkbox" name="relative_paths[]" value="<?php echo esc_attr((string)($orphan_row['path'] ?? '')); ?>"></td>
                                                <td><code><?php echo esc_html((string)($orphan_row['path'] ?? '')); ?></code></td>
                                                <td><?php echo esc_html((string)($orphan_row['type_label'] ?? '')); ?></td>
                                                <td class="rrze-msm-col-numeric"><?php echo esc_html((string)($orphan_row['size_label'] ?? '')); ?></td>
                                                <td><?php echo esc_html((string)($orphan_row['modified_label'] ?? '')); ?></td>
                                                <td class="rrze-msm-col-actions rrze-msm-col-actions-text">
                                                    <div class="rrze-msm-site-actions">
                                                        <?php if (!empty($orphan_row['file_url'])) { ?>
                                                            <a class="button button-secondary" href="<?php echo esc_url((string)$orphan_row['file_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Open file', 'rrze-multisite-manager'); ?></a>
                                                            <a class="button button-secondary" href="<?php echo esc_url('https://www.google.com/search?q=' . rawurlencode('"' . (string)$orphan_row['file_url'] . '"')); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Search on Google', 'rrze-multisite-manager'); ?></a>
                                                            <button
                                                                type="button"
                                                                class="button button-primary rrze-msm-open-orphan-file-delete-modal"
                                                                data-site-id="<?php echo esc_attr((string)$site_id); ?>"
                                                                data-file-path="<?php echo esc_attr((string)($orphan_row['path'] ?? '')); ?>"
                                                                data-delete-nonce="<?php echo esc_attr(wp_create_nonce('rrze_multisite_manager_delete_orphan_file_' . $site_id . '_' . (string)($orphan_row['path'] ?? ''))); ?>">
                                                                <?php echo esc_html__('Delete file', 'rrze-multisite-manager'); ?>
                                                            </button>
                                                        <?php } ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                                <div class="tablenav bottom">
                                    <div class="tablenav-pages rrze-msm-site-table-pagination" aria-label="<?php echo esc_attr__('Pagination', 'rrze-multisite-manager'); ?>"></div>
                                </div>
                            </div>
                            <p class="rrze-msm-site-actions">
                                <button
                                    type="button"
                                    class="button button-secondary rrze-msm-toggle-orphan-file-selection"
                                    data-select-label="<?php echo esc_attr__('Select all', 'rrze-multisite-manager'); ?>"
                                    data-unselect-label="<?php echo esc_attr__('Clear selection', 'rrze-multisite-manager'); ?>">
                                    <?php echo esc_html__('Select all', 'rrze-multisite-manager'); ?>
                                </button>
                                <button type="button" class="button button-primary rrze-msm-open-orphan-file-bulk-delete-modal" data-site-id="<?php echo esc_attr((string)$site_id); ?>" data-delete-nonce="<?php echo esc_attr(wp_create_nonce('rrze_multisite_manager_delete_orphan_files_' . $site_id)); ?>"><?php echo esc_html__('Delete selected files', 'rrze-multisite-manager'); ?></button>
                            </p>
                            </form>
                        <?php } ?>

                        <?php if (empty($storage_analysis['orphan_files_found_in_content']) && empty($storage_analysis['orphan_files_without_content_matches']) && empty($storage_analysis['unused_attachment_files'])) { ?>
                            <form method="post" action="<?php echo esc_url((string)$orphan_file_delete_action); ?>">
                                <input type="hidden" name="action" value="rrze_multisite_manager_delete_orphan_file">
                                <input type="hidden" name="site_id" value="<?php echo esc_attr((string)$site_id); ?>">
                                <?php wp_nonce_field('rrze_multisite_manager_delete_orphan_files_' . $site_id); ?>
                                <p class="rrze-msm-site-actions">
                                    <button
                                        type="button"
                                        class="button button-secondary rrze-msm-toggle-orphan-file-selection"
                                        data-select-label="<?php echo esc_attr__('Select all', 'rrze-multisite-manager'); ?>"
                                        data-unselect-label="<?php echo esc_attr__('Clear selection', 'rrze-multisite-manager'); ?>">
                                        <?php echo esc_html__('Select all', 'rrze-multisite-manager'); ?>
                                    </button>
                                    <button type="button" class="button button-primary rrze-msm-open-orphan-file-bulk-delete-modal" data-site-id="<?php echo esc_attr((string)$site_id); ?>" data-delete-nonce="<?php echo esc_attr(wp_create_nonce('rrze_multisite_manager_delete_orphan_files_' . $site_id)); ?>"><?php echo esc_html__('Delete selected files', 'rrze-multisite-manager'); ?></button>
                                </p>
                            <div class="rrze-msm-site-table-wrap" data-table-id="orphan-files-fallback" data-default-per-page="20" data-current-page="1" data-sort-key="size" data-sort-direction="desc">
                                <div class="tablenav top">
                                    <div class="alignleft actions">
                                        <label for="rrze-msm-orphan-files-fallback-per-page"><?php echo esc_html__('Show:', 'rrze-multisite-manager'); ?></label>
                                        <select class="rrze-msm-site-table-per-page" id="rrze-msm-orphan-files-fallback-per-page">
                                            <option value="20" selected><?php echo esc_html__('Standard (20)', 'rrze-multisite-manager'); ?></option>
                                            <option value="10">10</option>
                                            <option value="30">30</option>
                                            <option value="50">50</option>
                                            <option value="100">100</option>
                                        </select>
                                    </div>
                                </div>
                                <table class="widefat striped rrze-msm-table">
                                    <thead>
                                        <tr>
                                            <th><?php echo esc_html__('Selection', 'rrze-multisite-manager'); ?></th>
                                            <th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="name" data-sort-direction="asc"><span><?php echo esc_html__('File', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="type" data-sort-direction="asc"><span><?php echo esc_html__('Type', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th class="rrze-msm-col-numeric"><button type="button" class="rrze-msm-site-table-sort" data-sort-key="size" data-sort-direction="desc"><span><?php echo esc_html__('Size', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th><button type="button" class="rrze-msm-site-table-sort" data-sort-key="modified" data-sort-direction="desc"><span><?php echo esc_html__('Last modified', 'rrze-multisite-manager'); ?></span><span class="rrze-msm-site-table-sort-indicator" aria-hidden="true"></span></button></th>
                                            <th class="rrze-msm-col-actions rrze-msm-col-actions-text"><?php echo esc_html__('Actions', 'rrze-multisite-manager'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ((array)$storage_analysis['largest_orphan_files'] as $orphan_row) { ?>
                                            <tr data-sort-name="<?php echo esc_attr(mb_strtolower((string)($orphan_row['path'] ?? ''))); ?>" data-sort-type="<?php echo esc_attr(mb_strtolower((string)($orphan_row['type_label'] ?? ''))); ?>" data-sort-size="<?php echo esc_attr((string)($orphan_row['size_bytes'] ?? 0)); ?>" data-sort-modified="<?php echo esc_attr((string)($orphan_row['modified_timestamp'] ?? 0)); ?>">
                                                <td><input type="checkbox" name="relative_paths[]" value="<?php echo esc_attr((string)($orphan_row['path'] ?? '')); ?>"></td>
                                                <td><code><?php echo esc_html((string)($orphan_row['path'] ?? '')); ?></code></td>
                                                <td><?php echo esc_html((string)($orphan_row['type_label'] ?? '')); ?></td>
                                                <td class="rrze-msm-col-numeric"><?php echo esc_html((string)($orphan_row['size_label'] ?? '')); ?></td>
                                                <td><?php echo esc_html((string)($orphan_row['modified_label'] ?? '')); ?></td>
                                                <td class="rrze-msm-col-actions rrze-msm-col-actions-text">
                                                    <div class="rrze-msm-site-actions">
                                                        <?php if (!empty($orphan_row['file_url'])) { ?>
                                                            <a class="button button-secondary" href="<?php echo esc_url((string)$orphan_row['file_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Open file', 'rrze-multisite-manager'); ?></a>
                                                            <a class="button button-secondary" href="<?php echo esc_url('https://www.google.com/search?q=' . rawurlencode('"' . (string)$orphan_row['file_url'] . '"')); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Search on Google', 'rrze-multisite-manager'); ?></a>
                                                            <button
                                                                type="button"
                                                                class="button button-primary rrze-msm-open-orphan-file-delete-modal"
                                                                data-site-id="<?php echo esc_attr((string)$site_id); ?>"
                                                                data-file-path="<?php echo esc_attr((string)($orphan_row['path'] ?? '')); ?>"
                                                                data-delete-nonce="<?php echo esc_attr(wp_create_nonce('rrze_multisite_manager_delete_orphan_file_' . $site_id . '_' . (string)($orphan_row['path'] ?? ''))); ?>">
                                                                <?php echo esc_html__('Delete file', 'rrze-multisite-manager'); ?>
                                                            </button>
                                                        <?php } ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                                <div class="tablenav bottom">
                                    <div class="tablenav-pages rrze-msm-site-table-pagination" aria-label="<?php echo esc_attr__('Pagination', 'rrze-multisite-manager'); ?>"></div>
                                </div>
                            </div>
                            <p class="rrze-msm-site-actions">
                                <button
                                    type="button"
                                    class="button button-secondary rrze-msm-toggle-orphan-file-selection"
                                    data-select-label="<?php echo esc_attr__('Select all', 'rrze-multisite-manager'); ?>"
                                    data-unselect-label="<?php echo esc_attr__('Clear selection', 'rrze-multisite-manager'); ?>">
                                    <?php echo esc_html__('Select all', 'rrze-multisite-manager'); ?>
                                </button>
                                <button type="button" class="button button-primary rrze-msm-open-orphan-file-bulk-delete-modal" data-site-id="<?php echo esc_attr((string)$site_id); ?>" data-delete-nonce="<?php echo esc_attr(wp_create_nonce('rrze_multisite_manager_delete_orphan_files_' . $site_id)); ?>"><?php echo esc_html__('Delete selected files', 'rrze-multisite-manager'); ?></button>
                            </p>
                            </form>
                        <?php } ?>
                    <?php } elseif (!$orphanAnalysisComplete) { ?>
                        <div class="notice notice-info inline">
                            <p><?php echo esc_html__('The base analysis has been completed. Start and complete the orphan check before a reliable statement about potentially orphaned files can be made.', 'rrze-multisite-manager'); ?></p>
                        </div>
                    <?php } else { ?>
                        <p><?php echo esc_html__('No potentially orphaned files were detected.', 'rrze-multisite-manager'); ?></p>
                    <?php } ?>
                </section>
            <?php } else { ?>
                <section class="rrze-msm-widget rrze-msm-widget-span-12">
                    <div class="notice notice-info inline">
                        <p><?php echo esc_html__('There is no completed storage analysis for this website yet. Start the base analysis above. As soon as it finishes, the detail tables will appear here.', 'rrze-multisite-manager'); ?></p>
                    </div>
                </section>
            <?php } ?>

                <div class="rrze-msm-modal" id="rrze-msm-orphan-file-delete-modal" hidden>
                    <div class="rrze-msm-modal-backdrop rrze-msm-close-orphan-file-delete-modal"></div>
                    <div class="rrze-msm-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="rrze-msm-orphan-file-delete-title">
                        <h3 id="rrze-msm-orphan-file-delete-title"><?php echo esc_html__('Permanently delete file', 'rrze-multisite-manager'); ?></h3>
                        <p class="rrze-msm-modal-text" id="rrze-msm-orphan-file-delete-message"><?php echo esc_html__('This file will be removed directly from the uploads folder. This cannot be undone.', 'rrze-multisite-manager'); ?></p>
                        <p class="rrze-msm-modal-target">
                            <strong><?php echo esc_html__('File:', 'rrze-multisite-manager'); ?></strong>
                            <span id="rrze-msm-orphan-file-delete-target"></span>
                        </p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="rrze_multisite_manager_delete_orphan_file">
                            <input type="hidden" name="site_id" id="rrze-msm-orphan-file-delete-site-id" value="">
                            <input type="hidden" name="relative_path" id="rrze-msm-orphan-file-delete-path" value="">
                            <input type="hidden" name="_wpnonce" id="rrze-msm-orphan-file-delete-nonce" value="">
                            <div id="rrze-msm-orphan-file-delete-paths"></div>
                            <label class="rrze-msm-modal-checkbox">
                                <input type="checkbox" id="rrze-msm-orphan-file-delete-confirm">
                                <span><?php echo esc_html__('I understand that the file will be permanently deleted.', 'rrze-multisite-manager'); ?></span>
                            </label>
                            <div class="rrze-msm-modal-actions">
                                <button type="button" class="button button-secondary rrze-msm-close-orphan-file-delete-modal"><?php echo esc_html__('Cancel', 'rrze-multisite-manager'); ?></button>
                                <button type="submit" class="button button-primary" id="rrze-msm-orphan-file-delete-submit"><?php echo esc_html__('Permanently delete file', 'rrze-multisite-manager'); ?></button>
                            </div>
                        </form>
                    </div>
                </div>

                <script>
                function rrzeMsmGetOrphanSelectionInputs(form, visibleOnly) {
                    var inputs = [];
                    var allInputs = [];
                    var input = null;
                    var row = null;
                    var index = 0;

                    if (!form) {
                        return inputs;
                    }

                    allInputs = form.querySelectorAll('input[name="relative_paths[]"], input[name="attachment_ids[]"]');

                    for (index = 0; index < allInputs.length; index++) {
                        input = allInputs[index];
                        row = input.closest('tr');

                        if (visibleOnly && row && row.style.display === 'none') {
                            continue;
                        }

                        inputs.push(input);
                    }

                    return inputs;
                }

                function rrzeMsmUpdateOrphanSelectionButtons(form) {
                    var buttons = null;
                    var inputs = [];
                    var allSelected = false;
                    var index = 0;
                    var selectLabel = '';
                    var unselectLabel = '';

                    if (!form) {
                        return;
                    }

                    buttons = form.querySelectorAll('.rrze-msm-toggle-orphan-file-selection');
                    inputs = rrzeMsmGetOrphanSelectionInputs(form, true);
                    allSelected = inputs.length > 0;

                    for (index = 0; index < inputs.length; index++) {
                        if (!inputs[index].checked) {
                            allSelected = false;
                            break;
                        }
                    }

                    for (index = 0; index < buttons.length; index++) {
                        selectLabel = buttons[index].getAttribute('data-select-label') || '';
                        unselectLabel = buttons[index].getAttribute('data-unselect-label') || '';
                        buttons[index].textContent = allSelected ? unselectLabel : selectLabel;
                        buttons[index].setAttribute('aria-pressed', allSelected ? 'true' : 'false');
                    }
                }

                function rrzeMsmToggleOrphanSelection(event) {
                    var button = event.currentTarget;
                    var form = null;
                    var inputs = [];
                    var shouldSelectAll = false;
                    var index = 0;

                    if (!button) {
                        return;
                    }

                    form = button.closest('form');

                    if (!form) {
                        return;
                    }

                    inputs = rrzeMsmGetOrphanSelectionInputs(form, true);

                    for (index = 0; index < inputs.length; index++) {
                        if (!inputs[index].checked) {
                            shouldSelectAll = true;
                            break;
                        }
                    }

                    for (index = 0; index < inputs.length; index++) {
                        inputs[index].checked = shouldSelectAll;
                    }

                    rrzeMsmUpdateOrphanSelectionButtons(form);
                }

                function rrzeMsmHandleOrphanSelectionChange(event) {
                    var input = event.currentTarget;
                    var form = null;

                    if (!input) {
                        return;
                    }

                    form = input.closest('form');

                    if (!form) {
                        return;
                    }

                    rrzeMsmUpdateOrphanSelectionButtons(form);
                }

                function rrzeMsmInitOrphanSelectionToggle() {
                    var buttons = document.querySelectorAll('.rrze-msm-toggle-orphan-file-selection');
                    var inputs = document.querySelectorAll('input[name="relative_paths[]"], input[name="attachment_ids[]"]');
                    var index = 0;
                    var form = null;

                    for (index = 0; index < buttons.length; index++) {
                        buttons[index].addEventListener('click', rrzeMsmToggleOrphanSelection);
                        form = buttons[index].closest('form');

                        if (form) {
                            rrzeMsmUpdateOrphanSelectionButtons(form);
                        }
                    }

                    for (index = 0; index < inputs.length; index++) {
                        inputs[index].addEventListener('change', rrzeMsmHandleOrphanSelectionChange);
                    }
                }

                document.addEventListener('DOMContentLoaded', rrzeMsmInitOrphanSelectionToggle);
                </script>

            <?php } ?>
        <?php } ?>
    </div>
</div>
