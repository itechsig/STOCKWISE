<?php

namespace App\Support;

/**
 * The web app runs under `php artisan serve`, whose worker only receives a
 * handful of environment variables (ServeCommand::$passthroughVariables).
 * Under the STOCKWISE-Laravel service that leaves out SystemRoot & co., and
 * every child process that needs them fails — PowerShell with "Loading
 * managed Windows PowerShell failed with error 8009001d", Python with socket
 * errors. Pass these to every child process the web app starts.
 */
class WindowsEnv
{
    /** @return array<string, string> */
    public static function vars(): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return [];
        }

        $get = fn (string $name) => ($v = getenv($name)) !== false && $v !== '' ? $v : null;
        $root = $get('SystemRoot') ?? $get('SYSTEMROOT') ?? $get('windir') ?? 'C:\\Windows';
        $drive = substr($root, 0, 2);
        $temp = $get('TEMP') ?? $get('TMP') ?? $root.'\\Temp';

        $defaults = [
            'SystemRoot' => $root,
            'windir' => $root,
            'SystemDrive' => $drive,
            'ComSpec' => $root.'\\system32\\cmd.exe',
            'PATHEXT' => '.COM;.EXE;.BAT;.CMD;.VBS;.VBE;.JS;.JSE;.WSF;.WSH;.MSC',
            'ProgramData' => $drive.'\\ProgramData',
            'ALLUSERSPROFILE' => $drive.'\\ProgramData',
            'ProgramFiles' => $drive.'\\Program Files',
            'ProgramFiles(x86)' => $drive.'\\Program Files (x86)',
            'CommonProgramFiles' => $drive.'\\Program Files\\Common Files',
            'TEMP' => $temp,
            'TMP' => $temp,
            'PSModulePath' => $root.'\\system32\\WindowsPowerShell\\v1.0\\Modules',
            'USERPROFILE' => $root.'\\system32\\config\\systemprofile',
            'APPDATA' => $root.'\\system32\\config\\systemprofile\\AppData\\Roaming',
            'LOCALAPPDATA' => $root.'\\system32\\config\\systemprofile\\AppData\\Local',
        ];

        $vars = [];
        foreach ($defaults as $name => $default) {
            $vars[$name] = $get($name) ?? $default;
        }

        $path = $get('PATH') ?? $get('Path') ?? '';
        foreach ([$root.'\\system32', $root, $root.'\\System32\\Wbem', $root.'\\System32\\WindowsPowerShell\\v1.0\\'] as $dir) {
            if (stripos(';'.$path.';', ';'.$dir.';') === false) {
                $path = rtrim($path, ';').';'.$dir;
            }
        }
        $vars['PATH'] = ltrim($path, ';');

        return $vars;
    }
}
