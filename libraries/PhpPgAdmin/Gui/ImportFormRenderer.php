<?php

namespace PhpPgAdmin\Gui;

use PhpPgAdmin\Core\AbstractContext;
use PhpPgAdmin\Core\AppContainer;
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
        // determine if current user is superuser to show admin controls
        $pg = $this->postgres();
        $roleActions = new RoleActions($pg);
        $isSuper = $roleActions->isSuperUser();
        ?>
        <form id="importForm" method="post" enctype="multipart/form-data" action="dbimport.php?action=upload">
            <div class="form-group">
                <label for="file"><?= $lang['struploadfile'] ?></label>
                <input type="file" name="file" id="file" <?= $maxAttr ?>         <?= $chunkAttr ?> />
            </div>

            <input type="hidden" name="scope" id="import_scope" value="<?= htmlspecialchars($scope) ?>" />
            <input type="hidden" name="scope_ident" id="import_scope_ident"
                value="<?= htmlspecialchars($options['scope_ident'] ?? '') ?>" />

            <div class="form-group">
                <label><?= $lang['stroptions'] ?></label>
                <ul>
                    <?php if ($scope === 'server'): ?>
                        <li><label><input type="checkbox" name="opt_roles" /> <?= $lang['strimportroles'] ?></label></li>
                        <li><label><input type="checkbox" name="opt_tablespaces" /> <?= $lang['strimporttablespaces'] ?></label>
                        </li>
                    <?php endif; ?>
                    <?php if ($scope === 'database' || $scope === 'server'): ?>
                        <li><label><input type="checkbox" name="opt_schema_create" /> <?= $lang['strcreateschema'] ?></label></li>
                    <?php endif; ?>
                    <?php if ($scope === 'schema' || $scope === 'table'): ?>
                        <li><label><input type="checkbox" name="opt_truncate" /> <?= $lang['strtruncatebefore'] ?></label></li>
                    <?php endif; ?>
                    <li><label><input type="checkbox" name="opt_defer_self" checked /> <?= $lang['strdeferself'] ?></label></li>
                </ul>
            </div>

            <div class="form-group">
                <label><input type="checkbox" name="opt_auto_start" id="opt_auto_start" <?= ($importCfg['auto_start_default'] ?? false) ? 'checked' : '' ?> /> <?= $lang['strautostart'] ?? 'Auto-start import after upload' ?></label>
            </div>

            <div class="form-group">
                <button type="button" id="importStart"><?= $lang['strupload'] ?></button>
            </div>
        </form>

        <div id="importUI">
            <label><?= $lang['strupload'] ?> progress</label>
            <progress id="uploadProgress" value="0" max="100" style="width:100%"></progress>
            <label>Import progress</label>
            <progress id="importProgress" value="0" max="100" style="width:100%"></progress>
            <pre id="importLog" style="height:200px;overflow:auto;border:1px solid #ccc;padding:6px"></pre>
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
