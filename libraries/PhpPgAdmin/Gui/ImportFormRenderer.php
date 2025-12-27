<?php

namespace PhpPgAdmin\Gui;

use PhpPgAdmin\Core\AbstractContext;
use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Import\CompressionReader;
use PhpPgAdmin\Database\Actions\RoleActions;

class ImportFormRenderer extends AbstractContext
{
    public function renderImportForm(string $scope, array $options = []): void
    {
        $lang = $this->lang();
        $conf = $this->conf();
        $importCfg = $conf['import'] ?? [];
        $maxSize = (int) ($importCfg['upload_max_size'] ?? 0);
        $chunkSize = (int) ($importCfg['chunk_size'] ?? 0);
        $maxAttr = $maxSize > 0 ? 'data-import-max-size="' . htmlspecialchars((string) $maxSize) . '"' : '';
        $chunkAttr = $chunkSize > 0 ? 'data-import-chunk-size="' . htmlspecialchars((string) $chunkSize) . '"' : '';

        $caps = CompressionReader::capabilities();
        $capsAttr = sprintf(
            'data-cap-gzip="%s" data-cap-zip="%s" data-cap-bzip2="%s"',
            $caps['gzip'] ? '1' : '0',
            $caps['zip'] ? '1' : '0',
            $caps['bzip2'] ? '1' : '0'
        );
        // determine if current user is superuser to show admin controls
        $pg = $this->postgres();
        $roleActions = new RoleActions($pg);
        $isSuper = $roleActions->isSuperUser();
        ?>
        <form id="importForm" method="post" enctype="multipart/form-data" action="dbimport.php?action=upload">
            <div class="form-group">
                <label for="file"><?= $lang['struploadfile'] ?></label>
                <input type="file" name="file" id="file" <?= $capsAttr ?>         <?= $maxAttr ?>         <?= $chunkAttr ?> />
                <div id="importCompressionCaps" style="margin-top:6px">
                    <strong><?= $lang['strimportcompressioncaps'] ?? 'Compression support' ?>:</strong>
                    <?= ($caps['zip'] ? ($lang['strsupported'] ?? 'Supported') : ($lang['strunsupported'] ?? 'Unsupported')) ?>
                    ZIP,
                    <?= ($caps['gzip'] ? ($lang['strsupported'] ?? 'Supported') : ($lang['strunsupported'] ?? 'Unsupported')) ?>
                    gzip,
                    <?= ($caps['bzip2'] ? ($lang['strsupported'] ?? 'Supported') : ($lang['strunsupported'] ?? 'Unsupported')) ?>
                    bzip2
                </div>
            </div>

            <input type="hidden" name="scope" id="import_scope" value="<?= htmlspecialchars($scope) ?>" />
            <input type="hidden" name="scope_ident" id="import_scope_ident"
                value="<?= htmlspecialchars($options['scope_ident'] ?? '') ?>" />

            <div class="form-group">
                <label><?= $lang['stroptions'] ?></label>
                <ul>
                    <?php if ($scope === 'server'): ?>
                        <li><label><input type="checkbox" name="opt_roles" checked /> <?= $lang['strimportroles'] ?></label></li>
                        <li><label><input type="checkbox" name="opt_tablespaces" checked />
                                <?= $lang['strimporttablespaces'] ?></label></li>
                        <li><label><input type="checkbox" name="opt_databases" checked />
                                <?= $lang['strimportdatabases'] ?? 'Import databases' ?></label></li>
                    <?php endif; ?>
                    <?php if ($scope === 'database' || $scope === 'server'): ?>
                        <li><label><input type="checkbox" name="opt_schema_create" checked />
                                <?= $lang['strcreateschema'] ?></label></li>
                    <?php endif; ?>
                    <li><label><input type="checkbox" name="opt_data" checked />
                            <?= $lang['strimportdata'] ?? 'Import data (COPY/INSERT)' ?></label></li>
                    <?php if ($scope === 'schema' || $scope === 'table'): ?>
                        <li><label><input type="checkbox" name="opt_truncate" /> <?= $lang['strtruncatebefore'] ?></label></li>
                    <?php endif; ?>
                    <li><label><input type="checkbox" name="opt_ownership" checked />
                            <?= $lang['strimportownership'] ?? 'Apply ownership (ALTER ... OWNER)' ?></label></li>
                    <li><label><input type="checkbox" name="opt_rights" checked />
                            <?= $lang['strimportrights'] ?? 'Apply rights (GRANT/REVOKE)' ?></label></li>
                    <li><label><input type="checkbox" name="opt_defer_self" checked /> <?= $lang['strdeferself'] ?></label></li>
                    <li><label><input type="checkbox" name="opt_allow_drops" />
                            <?= $lang['strimportallowdrops'] ?? 'Allow DROP statements' ?></label></li>
                </ul>
            </div>

            <div class="form-group">
                <label><?= $lang['strerrorhandling'] ?? 'Error handling' ?>:</label>
                <ul>
                    <li><label><input type="radio" name="opt_error_mode" value="abort" checked />
                            <?= $lang['strimporterrorabort'] ?? 'Abort on first error' ?></label></li>
                    <li><label><input type="radio" name="opt_error_mode" value="log" />
                            <?= $lang['strimporterrorlog'] ?? 'Log errors and continue' ?></label></li>
                    <li><label><input type="radio" name="opt_error_mode" value="ignore" />
                            <?= $lang['strimporterrorignore'] ?? 'Ignore errors (not recommended)' ?></label></li>
                </ul>
            </div>

            <div class="form-group">
                <label><input type="checkbox" name="opt_auto_start" id="opt_auto_start" <?= ($importCfg['auto_start_default'] ?? false) ? 'checked' : '' ?> /> <?= $lang['strautostart'] ?? 'Auto-start import after upload' ?></label>
            </div>

            <div class="form-group">
                <button type="button" id="importStart"><?= $lang['strupload'] ?></button>
            </div>
        </form>

        <div id="importUI" style="display:none;margin-top:16px">
            <div id="uploadPhase">
                <h4><?= $lang['strupload'] ?>         <?= $lang['strprogress'] ?? 'Progress' ?></h4>
                <progress id="uploadProgress" value="0" max="100" style="width:100%"></progress>
                <div id="uploadStatus" style="margin-top:4px;font-size:0.9em;color:#666"></div>
            </div>
            <div id="importPhase" style="display:none;margin-top:16px">
                <h4><?= $lang['strimport'] ?>         <?= $lang['strprogress'] ?? 'Progress' ?></h4>
                <progress id="importProgress" value="0" max="100" style="width:100%"></progress>
                <div id="importStatus" style="margin-top:4px;font-size:0.9em;color:#666"></div>
                <pre id="importLog"
                    style="height:200px;overflow:auto;border:1px solid #ccc;padding:6px;margin-top:8px;background:#f9f9f9"></pre>
            </div>
        </div>

        <div id="uploadedJobs" style="margin-top:12px">
            <h4><?= $lang['struploads'] ?? 'Uploaded Jobs' ?></h4>
            <?php if ($isSuper): ?>
                <div style="margin-bottom:8px">
                    <label><input type="checkbox" id="opt_show_all" />
                        <?= $lang['strshowalljobs'] ?? 'Show all jobs (admin only)' ?></label>
                </div>
            <?php endif; ?>
            <div id="uploadedJobsList">Loading...</div>
        </div>

        <!-- Static modals for import UI (hidden by default) -->
        <div id="entrySelectorModal" class="import-modal"
            style="display:none;position:fixed;left:0;top:0;right:0;bottom:0;background:rgba(0,0,0,0.4);z-index:9999;">
            <div
                style="width:480px;margin:80px auto;background:#fff;padding:12px;border-radius:6px;box-shadow:0 2px 10px rgba(0,0,0,0.2);">
                <h3><?= $lang['strselectzipentry'] ?? 'Select file from ZIP to import' ?></h3>
                <div class="form-group">
                    <label for="entrySelect"><?= $lang['strfilename'] ?? 'Filename' ?></label>
                    <select id="entrySelect" style="width:100%"></select>
                </div>
                <div class="form-group">
                    <label><input type="checkbox" id="import_all_chk" />
                        <?= $lang['strimportall'] ?? 'Import all entries alphabetically' ?></label>
                </div>
                <div style="margin-top:8px">
                    <button id="entryImportBtn"><?= $lang['strimport'] ?? 'Import' ?></button>
                    <button id="entryCancelBtn" style="margin-left:8px"><?= $lang['strcancel'] ?? 'Cancel' ?></button>
                </div>
            </div>
        </div>

        <div id="jobListModal" class="import-modal"
            style="display:none;position:fixed;left:0;top:0;right:0;bottom:0;background:rgba(0,0,0,0.4);z-index:9999;">
            <div
                style="width:640px;margin:40px auto;background:#fff;padding:12px;border-radius:6px;box-shadow:0 2px 10px rgba(0,0,0,0.2);">
                <h3><?= $lang['strimportjobs'] ?? 'Import Jobs' ?></h3>
                <div id="jobListContainer" style="max-height:400px;overflow:auto;margin-top:8px"></div>
                <div style="margin-top:8px"><button id="jobListClose"><?= $lang['strclose'] ?? 'Close' ?></button></div>
            </div>
        </div>

        <script src="js/import.js"></script>
        <?php
    }
}
