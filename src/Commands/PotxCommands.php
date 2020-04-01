<?php

namespace Drupal\potx\Commands;

use Drush\Commands\DrushCommands;

/**
 * Extract the translatable strings from Drupal code into po files.
 */
class PotxCommands extends DrushCommands
{

    /**
     * Extract translatable strings from Drupal source code.
     *
     * @command potx
     *
     * @param string $mode
     *   Optional potx output mode e.g. single, multiple, core.
     * @param array $options
     *   Command options.
     *
     * @throws \Exception
     *
     * @option modules Comma delimited list of modules to extract translatable
     *   strings from.
     * @option files Comma delimited list of files to extract translatable
     *   strings from.
     * @option folder Folder to begin translation extraction in. When no other
     *   option is set this defaults to current directory.
     * @option api Drupal core version to use for extraction settings.
     * @option language Language to include in the po file
     *
     * @usage potx single
     *   Extract translatable strings from applicable files in current
     *   directory and write to single output file
     * @usage potx multiple --modules=example
     *   Extract translatable strings from applicable files of example module
     *   and write to module-specific output file.
     * @usage potx --files=sites/all/modules/example/example.module
     *   Extract translatable strings from example.module and write to single
     *   output file.
     * @usage potx single --api=8 --folder=projects/drupal/8
     *   Extract strings from folder projects/drupal/8 using API version 8.
     * @aliases
     */
    public function potx(
        $mode = null,
        array $options = [
            'modules' => null,
            'files' => null,
            'folder' => null,
            'api' => null,
            'language' => null,
          ]
    ) {
        // Include library.
        include_once __DIR__ . '/../../potx.inc';
        include_once __DIR__ . '/../../potx.local.inc';

        $files = [];
        $build_mode = POTX_BUILD_SINGLE;

        if ($mode !== null && in_array($mode, ['core', 'multiple', 'single'], false)) {
            // First argument could be any of the mode names.
            $build_mode = constant('POTX_BUILD_' . strtoupper($mode));
        }

        // Silence error message reporting. Messages will be reported by at the end.
        potx_status('set', POTX_STATUS_SILENT);

        // Get Drush options.
        $modules_option = $options['modules'];
        $files_option = $options['files'];
        $folder_option = $options['folder'];
        $api_option = $options['api'];
        $language_option = $options['language'];
        if (empty($api_option) || !in_array($api_option, [5, 6, 7, 8])) {
            $api_option = POTX_API_CURRENT;
        }

        potx_local_init($folder_option);

        if (!empty($modules_option)) {
            $modules = explode(',', $modules_option);
            foreach ($modules as $module) {
                $module_files = _potx_explore_dir(
                    drupal_get_path('module', $module) . '/',
                    '*',
                    $api_option,
                    true
                );
                $files = array_merge($files, $module_files);
            }
        } elseif (!empty($files_option)) {
            $files = explode(',', $files_option);
        } elseif (!empty($folder_option)) {
            $files = _potx_explore_dir($folder_option, '*', $api_option, true);
        } else {
            // No file list provided so autodiscover files in current directory.
            $files = _potx_explore_dir(
                drush_cwd() . '/',
                '*',
                $api_option,
                true
            );
        }

        foreach ($files as $file) {
            $this->io()->text(dt('Processing @file...', ['@file' => $file]));
            _potx_process_file(
                $file,
                0,
                '_potx_save_string',
                '_potx_save_version',
                $api_option
            );
        }

        potx_finish_processing('_potx_save_string', $api_option);

        _potx_build_files(
            POTX_STRING_RUNTIME,
            $build_mode,
            'general',
            '_potx_save_string',
            '_potx_save_version',
            '_potx_get_header',
            $language_option,
            $language_option,
            $api_option
        );
        _potx_build_files(POTX_STRING_INSTALLER, POTX_BUILD_SINGLE, 'installer');
        _potx_write_files();

        // Print the results.
        $this->io()->newLine();
        $this->io()->title(dt('Statistics'));

        // Get errors, if any.
        $errors = potx_status('get');

        // Get saved strings.
        $strings = _potx_save_string(null, null, null, 0, POTX_STRING_RUNTIME);

        // Add the table.
        $header = [
          'files' => dt('Files'),
          'strings' => dt('Strings'),
          'warnings' => dt('Warnings'),
        ];
        $rows = [];
        $rows[] = [count($files), count($strings), count($errors)];
        $this->io()->table($header, $rows);


        if (!empty($errors)) {
            $this->io()->title(dt('Errors'));
            foreach ($errors as $error) {
                throw new \Exception($error);
            }
        }

        $this->io()->newLine();
        $this->io()->text(dt("Done"));
    }
}
