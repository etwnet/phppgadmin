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
        $chunkSize = (int) ($importCfg['upload_chunk_size'] ?? ($importCfg['chunk_size'] ?? 0));
        $maxAttr = $maxSize > 0 ? 'data-import-max-size="' . htmlspecialchars((string) $maxSize) . '"' : '';
        $chunkAttr = $chunkSize > 0 ? 'data-import-chunk-size="' . htmlspecialchars((string) $chunkSize) . '"' : '';

        $caps = CompressionReader::capabilities();
        $capsPrintable = array_filter($caps, function ($v) {
            return $v;
        });
        $capsPrintable = array_keys($capsPrintable);
        if (empty($capsPrintable)) {
            $capsPrintable = ['none'];
        }
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
            <input type="hidden" name="scope" id="import_scope" value="<?= htmlspecialchars($scope) ?>" />
            <input type="hidden" name="scope_ident" id="import_scope_ident"
                value="<?= htmlspecialchars($options['scope_ident'] ?? '') ?>" />
            <input type="hidden" name="server" id="import_server" value="<?= htmlspecialchars($_REQUEST['server'] ?? '') ?>" />

            <fieldset>
                <legend><?= $lang['struploadfile'] ?></legend>
                <input type="file" name="file" id="file" <?= $capsAttr ?>         <?= $maxAttr ?>         <?= $chunkAttr ?> />
                <div id="importCompressionCaps" style="margin-top:6px">
                    <strong><?= $lang['strimportcompressioncaps'] ?? 'Compression support' ?>:</strong>
                    <?= implode(', ', $capsPrintable) ?>
                </div>
            </fieldset>

            <fieldset>
                <legend><?= $lang['stroptions'] ?></legend>
                <?php if ($scope === 'server'): ?>
                    <div><label><input type="checkbox" name="opt_roles" checked /> <?= $lang['strimportroles'] ?></label></div>
                    <div><label><input type="checkbox" name="opt_tablespaces" checked />
                            <?= $lang['strimporttablespaces'] ?></label></div>
                    <div><label><input type="checkbox" name="opt_databases" checked />
                            <?= $lang['strimportdatabases'] ?? 'Import databases' ?></label></div>
                <?php endif; ?>
                <?php if ($scope === 'database' || $scope === 'server'): ?>
                    <div><label><input type="checkbox" name="opt_schema_create" checked />
                            <?= $lang['strcreateschema'] ?></label></div>
                <?php endif; ?>
                <div><label><input type="checkbox" name="opt_data" checked />
                        <?= $lang['strimportdata'] ?? 'Import data (COPY/INSERT)' ?></label></div>
                <?php if ($scope === 'schema' || $scope === 'table'): ?>
                    <div><label><input type="checkbox" name="opt_truncate" /> <?= $lang['strtruncatebefore'] ?></label></div>
                <?php endif; ?>
                <div><label><input type="checkbox" name="opt_ownership" checked />
                        <?= $lang['strimportownership'] ?? 'Apply ownership (ALTER ... OWNER)' ?></label></div>
                <div><label><input type="checkbox" name="opt_rights" checked />
                        <?= $lang['strimportrights'] ?? 'Apply rights (GRANT/REVOKE)' ?></label></div>
                <div><label><input type="checkbox" name="opt_defer_self" checked /> <?= $lang['strdeferself'] ?></label></div>
                <div><label><input type="checkbox" name="opt_allow_drops" />
                        <?= $lang['strimportallowdrops'] ?? 'Allow DROP statements' ?></label></div>
            </fieldset>

            <fieldset>
                <legend><?= $lang['strerrorhandling'] ?? 'Error handling' ?></legend>
                <div><label><input type="radio" name="opt_error_mode" value="abort" checked />
                        <?= $lang['strimporterrorabort'] ?? 'Abort on first error' ?></label></div>
                <div><label><input type="radio" name="opt_error_mode" value="log" />
                        <?= $lang['strimporterrorlog'] ?? 'Log errors and continue' ?></label></div>
                <div><label><input type="radio" name="opt_error_mode" value="ignore" />
                        <?= $lang['strimporterrorignore'] ?? 'Ignore errors (not recommended)' ?></label></div>
            </fieldset>

            <fieldset>
                <legend><?= $lang['strautostart'] ?? 'Auto-start import after upload' ?></legend>
                <label><input type="checkbox" name="opt_auto_start" id="opt_auto_start" <?= ($importCfg['auto_start_default'] ?? false) ? 'checked' : '' ?> /> <?= $lang['strautostart'] ?? 'Auto-start import after upload' ?></label>
            </fieldset>

            <div class="form-group">
                <button type="button" id="importStart"><?= $lang['strupload'] ?></button>
            </div>
        </form>

        <div id="importUI" data-server="<?= htmlspecialchars($_REQUEST['server'] ?? '') ?>"
            style="display:none;margin-top:16px">
            <div id="uploadPhase">
                <h4><?= $lang['strupload'] ?>         <?= $lang['strprogress'] ?? 'Progress' ?></h4>
                <progress id="uploadProgress" value="0" max="100" style="width:100%"></progress>
                <div style="margin-top:8px">
                    <button id="uploadPauseBtn" type="button" style="display:none">Pause</button>
                    <button id="uploadCancelBtn" type="button" style="display:none;margin-left:4px">Cancel</button>
                    <span id="uploadStatus" style="margin-left:8px;font-size:0.9em;color:#666"></span>
                </div>
                <pre id="uploadLog"
                    style="height:100px;overflow:auto;border:1px solid #ccc;padding:6px;margin-top:8px;background:#f9f9f9;display:none"></pre>
            </div>
            <div id="importPhase" style="display:none;margin-top:16px">
                <h4><?= $lang['strimport'] ?> <span id="importJobTitle"
                        style="font-weight:normal;font-size:0.9em;color:#555"></span> -
                    <?= $lang['strprogress'] ?? 'Progress' ?>
                </h4>
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

        <template id="job-row-template">
            <div class="import-job-row">
                <div class="job-info"></div>
                <div class="job-actions">
                    <button class="job-btn view">View</button>
                    <button class="job-btn start">Start</button>
                    <button class="job-btn cancel">Cancel</button>
                    <button class="job-btn resume">Resume</button>
                    <button class="job-btn delete">Delete</button>
                </div>
            </div>
        </template>

        <!-- Static modals for import UI (hidden by default) -->
        <div id="entrySelectorModal" class="import-panel"
            style="display:none;margin-top:12px;border:1px solid #ddd;padding:10px;background:#fff;">
            <h3 style="margin-top:0;"><?= $lang['strselectzipentry'] ?? 'Select file from ZIP to import' ?></h3>
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

        <script type="module" src="js/import.js"></script>
        <?php
    }
}
