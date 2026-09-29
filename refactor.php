<?php
$map = [
    'Absences' => ['Absence', 'AbsenceType'],
    'Assistants' => ['Assistant', 'AssistantException', 'AssistantTemporaryAssignments'],
    'Auth' => ['User', 'Role'],
    'Schedules' => ['Schedule', 'ScheduleEntry', 'AssistantScheduleProfile', 'AssistantScheduleShift', 'AssistantScheduleShiftDay'],
    'Schools' => ['School', 'SchoolOperatingRule', 'SchoolOperatingRuleDay'],
    'System' => ['ActivityType', 'Notification']
];

$projectRoot = __DIR__;
$modelsDir = $projectRoot . '/app/Models';
$policiesDir = $projectRoot . '/app/Policies';

// Create directories
foreach (array_keys($map) as $folder) {
    if (!is_dir("$modelsDir/$folder")) mkdir("$modelsDir/$folder", 0777, true);
    if (!is_dir("$policiesDir/$folder")) mkdir("$policiesDir/$folder", 0777, true);
}

$replacements = [];

// Move files and build replacements
foreach ($map as $folder => $classes) {
    foreach ($classes as $class) {
        $oldModel = "App\\Models\\$class";
        $newModel = "App\\Models\\$folder\\$class";
        $replacements[$oldModel] = $newModel;

        $oldPolicy = "App\\Policies\\{$class}Policy";
        $newPolicy = "App\\Policies\\$folder\\{$class}Policy";
        $replacements[$oldPolicy] = $newPolicy;

        // Move Model
        if (file_exists("$modelsDir/$class.php")) {
            rename("$modelsDir/$class.php", "$modelsDir/$folder/$class.php");
            $content = file_get_contents("$modelsDir/$folder/$class.php");
            $content = str_replace("namespace App\Models;", "namespace App\Models\\$folder;", $content);
            file_put_contents("$modelsDir/$folder/$class.php", $content);
        }

        // Move Policy
        if (file_exists("$policiesDir/{$class}Policy.php")) {
            rename("$policiesDir/{$class}Policy.php", "$policiesDir/$folder/{$class}Policy.php");
            $content = file_get_contents("$policiesDir/$folder/{$class}Policy.php");
            $content = str_replace("namespace App\Policies;", "namespace App\Policies\\$folder;", $content);
            file_put_contents("$policiesDir/$folder/{$class}Policy.php", $content);
        }
    }
}

// Find all PHP files to search and replace
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectRoot));
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php' && strpos($file->getPathname(), 'vendor') === false) {
        $path = $file->getPathname();
        if (basename($path) === 'refactor.php') continue;
        
        $content = file_get_contents($path);
        $changed = false;

        foreach ($replacements as $old => $new) {
            $useOld = "use $old;";
            $useNew = "use $new;";
            
            if (strpos($content, $useOld) !== false) {
                $content = str_replace($useOld, $useNew, $content);
                $changed = true;
            }
            
            // Replace strings
            if (strpos($content, "'$old'") !== false) {
                $content = str_replace("'$old'", "'$new'", $content);
                $changed = true;
            }
            if (strpos($content, "\"$old\"") !== false) {
                $content = str_replace("\"$old\"", "\"$new\"", $content);
                $changed = true;
            }
            
            // Replace docblocks or inline references
            // App\Models\User -> App\Models\Auth\User
            if (strpos($content, $old) !== false && strpos($content, "use $old") === false && strpos($content, "namespace $old") === false) {
                $content = str_replace("\\$old", "\\$new", $content);
                $content = str_replace(" $old", " $new", $content);
                $content = str_replace("($old", "($new", $content);
                $changed = true;
            }
        }

        if ($changed) {
            file_put_contents($path, $content);
        }
    }
}
echo "Refactoring completed.\n";
