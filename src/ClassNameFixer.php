<?php

namespace Sunnysideup\ClassNameFixer;

use Page;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\CMS\Model\SiteTreeLink;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectSchema;
use SilverStripe\ORM\DB;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Scan every DataObject table for invalid ClassName (and similar) values and
 * repair them in bulk.
 *
 * Resolution strategy (Value-Based Bulk Update):
 * 1. Find all distinct invalid values in a column.
 * 2. For each invalid value, attempt a short-name or table-name match.
 * 3. If unresolved, fall back to bestClassName() for the table (ClassName only).
 * 4. Issue a single parameterized UPDATE query replacing the old value with the new one.
 *
 * Run modes (Silverstripe 6):
 * - default          : DRY RUN — compute and log every proposed fix, do not touch the DB.
 * - --for-real       : compute, log, and execute.
 * - --dry-run        : force a dry run (takes precedence over --for-real).
 * - --verbosity=vvv  : 'v' = basic logging, 'vv' = log every proposed fix, 'vvv' = log even more.
 *
 * Run it via:
 *   sake tasks:check-class-names --for-real
 *   /dev/tasks/check-class-names?for-real=1
 */
class ClassNameFixer extends BuildTask
{
    protected static string $commandName = 'check-class-names';

    protected string $title = 'Check all tables for valid class names (Bulk Update)';

    protected static string $description = 'Check all tables for valid class names and resolve errors via bulk value-to-value updates.';

    private static bool $is_enabled = true;

    protected bool $dryRun = true;

    protected bool $extendFieldSize = true;

    protected array $onlyRunFor = [];

    protected array $listOfAllClasses = [];

    protected array $countsOfAllClasses = [];

    protected array $dbTablesPresent = [];

    protected $dataObjectSchema;

    protected array $bestClassNameStore = [];

    protected $tableNameToClassMap;

    protected string $verbose = 'v'; // 'v' = basic logging, 'vv' = log every proposed fix, 'vvv' = log even more

    protected ?PolyOutput $output = null;

    protected array $tableTimings = [];

    private static $other_fields_to_check = [
        'DNADesign\\Elemental\\Models\\ElementalArea' => [
            'OwnerClassName',
        ],
        SiteTreeLink::class => [
            'ParentClass',
        ],
    ];

    public function setDryRun(bool $dryRun): self
    {
        $this->dryRun = $dryRun;
        return $this;
    }

    public function getOptions(): array
    {
        return [
            new InputOption(
                'for-real',
                null,
                InputOption::VALUE_NONE,
                'Actually write changes to the database (default is a dry run).'
            ),
            new InputOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Force a dry run. Takes precedence over --for-real.'
            ),
            new InputOption(
                'verbosity',
                null,
                InputOption::VALUE_REQUIRED,
                "Logging level: 'v' (basic), 'vv' (every proposed fix) or 'vvv' (everything).",
                'v'
            ),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $this->output = $output;

        $verbosity = (string) $input->getOption('verbosity');
        if ($verbosity !== '') {
            $this->verbose = $verbosity;
        }

        // Default is a dry run. --for-real flips it; --dry-run always wins.
        if ($input->getOption('for-real')) {
            $this->dryRun = false;
        }
        if ($input->getOption('dry-run')) {
            $this->dryRun = true;
        }

        $this->announceRunMode();
        $this->loadKnownClasses();
        $this->loadDbTables();

        $this->dataObjectSchema = Injector::inst()->get(DataObjectSchema::class);

        $objectClassNames = array_keys($this->listOfAllClasses);
        foreach ($objectClassNames as $objectClassName) {
            if (count($this->onlyRunFor) && !in_array($objectClassName, $this->onlyRunFor, true)) {
                continue;
            }
            $this->processClass($objectClassName);
        }
        $this->findSuspiciousClassNames();
        $this->reportSlowest();

        return Command::SUCCESS;
    }

    // ----------------------------------------------------------------
    //                         Setup helpers
    // ----------------------------------------------------------------

    protected function announceRunMode()
    {
        $this->flushNowLine();
        if ($this->dryRun) {
            $this->flushNow('DRY RUN — nothing will be written to the database.', 'notice');
        } else {
            $this->flushNow('REAL RUN — changes will be applied to the database.', 'notice');
        }
        $this->flushNowLine();
    }

    protected function loadKnownClasses()
    {
        $this->listOfAllClasses = [];
        foreach (ClassInfo::subclassesFor(DataObject::class, false) as $className) {
            $this->listOfAllClasses[$className] = ClassInfo::shortName($className);
        }
        $this->countsOfAllClasses = array_count_values($this->listOfAllClasses);
        $this->tableNameToClassMap = null;
    }

    protected function loadDbTables()
    {
        $this->dbTablesPresent = [];
        foreach (DB::query('SHOW TABLES') as $row) {
            $table = array_pop($row);
            $this->dbTablesPresent[$table] = $table;
        }
    }

    // ----------------------------------------------------------------
    //                       Per-class orchestration
    // ----------------------------------------------------------------

    protected function processClass(string $objectClassName)
    {
        $start = microtime(true);
        $fields = $this->dataObjectSchema->databaseFields($objectClassName, false);
        if (count($fields) === 0) {
            if ($this->verbose === 'vvv') {
                $this->flushNow('... ' . $objectClassName . ' has no database fields, skipping.');
            }
            return;
        }

        $tableName = $this->dataObjectSchema->tableName($objectClassName);
        $this->flushNow('');
        $this->flushNowLine();
        $this->flushNow('Checking ' . $objectClassName . ' => ' . $tableName);

        $declared = Config::inst()->get($objectClassName, 'table_name');
        if ($declared !== $tableName && 'Page' !== $objectClassName) {
            $this->flushNow(
                '... ' . $objectClassName . ' POTENTIALLY has a table with a full class name: '
                . $tableName . ' — recommend setting private static $table_name explicitly',
                'error'
            );
        }

        if (!$tableName) {
            $this->flushNow('... Can not find: ' . $objectClassName . '.table_name in code ', 'error');
            return;
        }
        if (!$this->tableExists($tableName)) {
            $this->flushNow('... Can not find: ' . $tableName . ' in database.', 'error');
            return;
        }

        $count = DB::query('SELECT COUNT("ID") FROM "' . $tableName . '"')->value();
        if ($count === 0) {
            if ($this->verbose === 'vvv') {
                $this->flushNow('... Table exists but has no records.');
            }
            return;
        }
        if ($this->verbose === 'vvv') {
            $this->flushNow('... ' . $count . ' rows');
        }

        foreach ($this->fieldsToCheckFor($objectClassName) as $fieldName) {
            if (!$this->fieldExists($tableName, $fieldName)) {
                if ($this->verbose === 'vvv') {
                    $this->flushNow('... Can not find: ' . $tableName . '.' . $fieldName . ' in database.', 'error');
                }
                continue;
            }
            $this->fixClassNames($tableName, $objectClassName, $fieldName);
        }

        $seconds = microtime(true) - $start;
        $this->recordTiming($tableName, 'check', $seconds);
        $this->flushNow('... ' . $tableName . ' checked in ' . $this->formatDuration($seconds));
    }

    protected function fieldsToCheckFor(string $objectClassName): array
    {
        $fields = ['ClassName'];
        $extra = $this->config()->get('other_fields_to_check');
        if (isset($extra[$objectClassName])) {
            foreach ($extra[$objectClassName] as $f) {
                $fields[] = $f;
            }
        }
        return array_unique($fields);
    }

    // ----------------------------------------------------------------
    //                           Fixing logic
    // ----------------------------------------------------------------

    protected function fixClassNames(
        string $tableName,
        string $objectClassName,
        ?string $fieldName = 'ClassName',
        ?bool $versionedTable = false
    ) {
        $this->flushNow('Checking ' . $objectClassName . ' => ' . $tableName . '.' . $fieldName);

        $where = $this->buildWhereClause($fieldName);
        $rowsToFix = (int) DB::query('SELECT COUNT("ID") FROM "' . $tableName . '" WHERE ' . $where)->value();

        if ($rowsToFix === 0) {
            if ($this->verbose === 'vvv') {
                $this->flushNow('... no broken values');
            }
        } else {
            $this->reportErrorCounts($tableName, $fieldName, $where, $rowsToFix);

            if ($this->extendFieldSize && $fieldName === 'ClassName') {
                $this->fixFieldSize($tableName);
            }

            // Route ALL fields through the resolution logic now, not just ClassName
            $this->bulkFixByDistinctValue($tableName, $objectClassName, $fieldName, $where);
        }

        // Recurse into versioned variants
        if (false === $versionedTable) {
            foreach (['_Live', '_Versions'] as $extension) {
                $testTable = $tableName . $extension;
                if ($this->tableExists($testTable)) {
                    $this->fixClassNames($testTable, $objectClassName, $fieldName, true);
                } else {
                    if ($this->verbose === 'vvv') {
                        $this->flushNow('... No versioned table found for ' . $tableName . ' (' . $testTable . ')');
                    }
                }
            }
        }
    }

    protected function reportErrorCounts(string $tableName, string $fieldName, string $where, int $rowsToFix)
    {
        $totalCount = (int) DB::query('SELECT COUNT("ID") FROM "' . $tableName . '"')->value();
        if ($totalCount === $rowsToFix) {
            $this->flushNow('... All ' . $totalCount . ' rows in ' . $tableName . ' are broken', 'error');
            return;
        }
        $whereNull = $where . ' AND ("' . $fieldName . '" IS NULL OR "' . $fieldName . '" = \'\')';
        $whereBad = $where . ' AND NOT ("' . $fieldName . '" IS NULL OR "' . $fieldName . '" = \'\')';

        $nullCount = (int) DB::query('SELECT COUNT("ID") FROM "' . $tableName . '" WHERE ' . $whereNull)->value();
        $badCount = (int) DB::query('SELECT COUNT("ID") FROM "' . $tableName . '" WHERE ' . $whereBad)->value();

        $this->flushNow('... ' . $rowsToFix . ' errors in "' . $fieldName . '":');
        if ($nullCount) {
            $this->flushNow('... ... ' . $nullCount . ' rows have no ' . $fieldName . ' at all', 'error');
        }
        if ($badCount) {
            $this->flushNow('... ... ' . $badCount . ' rows have a bad ' . $fieldName);
        }
    }

    protected function bulkFixByDistinctValue(string $tableName, string $objectClassName, string $fieldName, string $where)
    {
        $rows = DB::query(
            'SELECT "' . $fieldName . '" AS bad_value, COUNT("ID") AS c
            FROM "' . $tableName . '"
            WHERE ' . $where . '
            GROUP BY "' . $fieldName . '"
            ORDER BY c DESC'
        );

        foreach ($rows as $row) {
            $originalValue = $row['bad_value'];
            $countForValue = (int) $row['c'];
            $isEmpty = ($originalValue === null || $originalValue === '');
            $displayValue = !$isEmpty ? $originalValue : '<empty/null>';

            // 1. Try resolving via short name match
            $resolved = !$isEmpty ? $this->findMatchingClassname($originalValue) : null;
            $reason = 'short-name match';

            // 2. Fallbacks
            if (!$resolved) {
                if ($fieldName === 'ClassName') {
                    // Only guess the "best" class for actual ClassName columns
                    $resolved = $this->bestClassName($objectClassName, $tableName, $fieldName);
                    $reason = 'fallback to best class';
                } else {
                    // For polymorphic relation fields, guessing is dangerous. Safest to wipe it.
                    $resolved = null;
                    $reason = 'unresolvable relation class (set to NULL)';
                }
            }

            $this->flushNow(
                '... ' . $countForValue . ' row(s): ' . $displayValue . ' → ' . ($resolved ?? 'NULL') . ' [' . $reason . ']',
                $resolved ? 'created' : 'deleted'
            );

            if ($isEmpty) {
                $this->applyUpdate(
                    'UPDATE "' . $tableName . '" SET "' . $fieldName . '" = ? WHERE "' . $fieldName . '" IS NULL OR "' . $fieldName . '" = \'\'',
                    [$resolved]
                );
            } else {
                $this->applyUpdate(
                    'UPDATE "' . $tableName . '" SET "' . $fieldName . '" = ? WHERE "' . $fieldName . '" = ?',
                    [$resolved, $originalValue]
                );
            }
        }
    }

    // ----------------------------------------------------------------
    //                       Lookup / resolution
    // ----------------------------------------------------------------

    protected function findMatchingClassname(string $className): ?string
    {
        if ($className === '') {
            return null;
        }
        $shortName = $this->getShortClassName($className);
        if ($shortName === '') {
            return null;
        }

        // 1. Try to resolve the short name exactly as given.
        $match = $this->matchShortName($shortName);
        if ($match !== null) {
            return $match;
        }

        // 2. Fallback: if the short name contains underscore(s) and nothing
        //    matched, retry with every underscore removed. This handles legacy
        //    "Some_Old_Class" style names whose modern equivalent is
        //    "SomeOldClass" (works for any number of underscores).
        if (str_contains($shortName, '_')) {
            $stripped = str_replace('_', '', $shortName);
            if ($stripped !== '' && $stripped !== $shortName) {
                return $this->matchShortName($stripped);
            }
        }

        return null;
    }

    /**
     * Resolve a single short class name to exactly one fully-qualified class
     * name, or null if there is no unambiguous match.
     *
     * Matching order:
     *   (a) exact short-class-name match
     *   (b) exact table-name match
     *   (c) trailing "_ShortName" table-name suffix match
     */
    protected function matchShortName(string $shortName): ?string
    {
        if ($shortName === '') {
            return null;
        }

        // (a) match by short class name
        $byShort = [];
        foreach ($this->listOfAllClasses as $fqcn => $fqcnShort) {
            if ($shortName === $fqcnShort) {
                $byShort[$fqcn] = $fqcn;
            }
        }
        if (count($byShort) === 1) {
            return array_values($byShort)[0];
        }
        if (count($byShort) > 1) {
            return null; // ambiguous — bail
        }

        // (b) match by table name
        $tableMap = $this->getTableNameToClassMap();
        if (isset($tableMap[$shortName]) && count($tableMap[$shortName]) === 1) {
            return $tableMap[$shortName][0];
        }

        // (c) match by trailing "_ShortName" table-name suffix
        $trailing = [];
        $suffix = '_' . $shortName;
        $suffixLen = strlen($suffix);
        foreach ($tableMap as $tableName => $fqcnList) {
            if (strlen($tableName) > $suffixLen && substr($tableName, -$suffixLen) === $suffix) {
                foreach ($fqcnList as $fqcn) {
                    $trailing[$fqcn] = $fqcn;
                }
            }
        }

        return count($trailing) === 1 ? array_values($trailing)[0] : null;
    }

    protected function getShortClassName(string $className): string
    {
        $pos = strrpos($className, '\\');
        return false === $pos ? $className : substr($className, $pos + 1);
    }

    protected function getTableNameToClassMap(): array
    {
        if (null !== $this->tableNameToClassMap) {
            return $this->tableNameToClassMap;
        }
        $map = [];
        foreach ($this->listOfAllClasses as $fqcn => $fqcnShort) {
            if (!class_exists($fqcn)) {
                continue;
            }
            try {
                $tableName = $this->dataObjectSchema->tableName($fqcn);
            } catch (\Throwable $e) {
                continue;
            }
            if ($tableName) {
                $map[$tableName][] = $fqcn;
            }
        }
        return $this->tableNameToClassMap = $map;
    }

    protected function buildWhereClause(string $fieldName): string
    {
        $escapedClasses = array_map('addslashes', array_keys($this->listOfAllClasses));
        return '"' . $fieldName . '" NOT IN (\'' . implode("', '", $escapedClasses) . '\')';
    }

    protected function bestClassName(string $objectClassName, string $tableName, string $fieldName): string
    {
        $key = $objectClassName . '_' . $tableName . '_' . $fieldName;
        if (isset($this->bestClassNameStore[$key])) {
            return $this->bestClassNameStore[$key];
        }

        $obj = Injector::inst()->get($objectClassName);
        if ($obj instanceof SiteTree && class_exists(Page::class)) {
            return $this->bestClassNameStore[$key] = 'Page';
        }

        // Safety check in case bestClassName is accidentally called on a non-enum field
        $dbField = $obj->dbObject($fieldName);
        $values = ($dbField && method_exists($dbField, 'enumValues')) ? $dbField->enumValues(false) : [];

        $best = '';
        $rowsForBest = DB::query(
            'SELECT "' . $fieldName . '", COUNT(*) AS magnitude
            FROM "' . $tableName . '"
            GROUP BY "' . $fieldName . '"
            ORDER BY magnitude DESC
            LIMIT 1'
        );

        foreach ($rowsForBest as $r) {
            if (in_array($r[$fieldName], $values, true)) {
                $best = $r[$fieldName];
                break;
            }
        }

        if (!$best && !empty($values)) {
            $best = key($values);
        }

        return $this->bestClassNameStore[$key] = $best ?: $objectClassName;
    }

    // ----------------------------------------------------------------
    //              Suspicious-value sweep over every column
    // ----------------------------------------------------------------

    protected function findSuspiciousClassNames()
    {
        $this->flushNow('');
        $this->flushNowLine();
        $this->flushNow('Scanning all tables for suspicious class-name-esque values');
        $this->flushNowLine();

        $unresolved = [];
        $manualPotentials = []; // Track the broad matches here

        foreach ($this->dbTablesPresent as $tableName) {
            $tableStart = microtime(true);
            $columns = DB::query('SHOW COLUMNS FROM "' . $tableName . '"');

            $scannedCols = 0;
            $skippedCols = 0;

            foreach ($columns as $col) {
                $fieldName = $col['Field'];

                // Only string-like columns can hold a class name. Skipping
                // numeric/date/blob columns avoids running a full-table
                // "LIKE '%\%'" scan (leading wildcard = no index) on data that
                // could never match, which is the main cost of this sweep.
                if (!$this->isTextColumn($col['Type'] ?? '')) {
                    $skippedCols++;
                    continue;
                }
                $scannedCols++;

                // Fetch distinct values containing a backslash
                $rows = DB::query(
                    'SELECT "' . $fieldName . '" AS row_value
                    FROM "' . $tableName . '"
                    WHERE "' . $fieldName . '" LIKE \'%\\\\%\'
                    GROUP BY "' . $fieldName . '"'
                );

                foreach ($rows as $row) {
                    $value = $row['row_value'] ?? '';

                    if ($value === '' || class_exists($value)) {
                        continue;
                    }

                    // 1. STRICT MATCH (Original behavior)
                    $isStrictMatch = preg_match('/^[A-Z][A-Za-z0-9_]*(\\\\[A-Z][A-Za-z0-9_]*)+$/', $value);

                    if ($isStrictMatch) {
                        $better = $this->findMatchingClassname($value);
                        if ($better) {
                            $this->flushNow(
                                '... ' . $tableName . '.' . $fieldName . ': ' . $value . ' → ' . $better . ' (Bulk updated)',
                                'created'
                            );
                            $this->applyUpdate(
                                'UPDATE "' . $tableName . '" SET "' . $fieldName . '" = ? WHERE "' . $fieldName . '" = ?',
                                [$better, $value]
                            );
                        } else {
                            $unresolved[] = [
                                'Table' => $tableName,
                                'Field' => $fieldName,
                                'Value' => $value,
                            ];
                        }
                    } else {
                        // 2. BROAD MATCH (Potentials for manual inclusion)
                        // Fails strict casing, but has no spaces/dashes and has an internal backslash.
                        $isBroadMatch = preg_match('/^[^\s\\\\\-]+(\\\\[^\s\\\\\-]+)+$/', $value);
                        if ($isBroadMatch) {
                            $manualPotentials[] = [
                                'Table' => $tableName,
                                'Field' => $fieldName,
                                'Value' => $value,
                            ];
                        }
                    }
                }
            }

            $seconds = microtime(true) - $tableStart;
            $this->recordTiming($tableName, 'scan', $seconds);
            // Only surface fast tables when extra verbosity is requested, to keep
            // the scan output readable; slow ones always show and all are recorded.
            if ($this->verbose !== 'v' || $seconds >= 0.5) {
                $this->flushNow(
                    '... scanned ' . $tableName . ' in ' . $this->formatDuration($seconds)
                    . ' (' . $scannedCols . ' text col(s), ' . $skippedCols . ' skipped)'
                );
            }
        }

        // --- Output Results ---

        if (count($unresolved) > 0) {
            $this->flushNow('... ' . count($unresolved) . ' strict suspicious values could not be auto-remapped:', 'error');
            foreach ($unresolved as $u) {
                $this->flushNow('... ... ' . $u['Table'] . '.' . $u['Field'] . ' Value: ' . $u['Value']);
            }
        } else {
            $this->flushNow('... no unresolved strict suspicious values', 'created');
        }

        if (count($manualPotentials) > 0) {
            $this->flushNow('');
            $this->flushNow('... ' . count($manualPotentials) . ' potential values for manual inclusion (did not match strict casing):', 'notice');
            foreach ($manualPotentials as $m) {
                $this->flushNow('... ... [MANUAL CHECK] ' . $m['Table'] . '.' . $m['Field'] . ' Value: ' . $m['Value']);
            }
        }
    }

    // ----------------------------------------------------------------
    //                            Write gate
    // ----------------------------------------------------------------

    protected function applyUpdate(string $sql, array $params = [])
    {
        if ($this->dryRun) {
            return;
        }

        if (empty($params)) {
            DB::query($sql);
        } else {
            DB::prepared_query($sql, $params);
        }
    }

    protected function fixFieldSize(string $tableName)
    {
        if ($this->dryRun) {
            return;
        }

        try {
            DB::query('ALTER TABLE "' . $tableName . '" MODIFY "ClassName" VARCHAR(255)');
        } catch (\Exception $e) {
            // Silently skip
        }
    }

    // ----------------------------------------------------------------
    //                            Output
    // ----------------------------------------------------------------

    public function flushNow(string $message = '', ?string $type = ''): void
    {
        if (null === $this->output) {
            return;
        }

        [$open, $close] = $this->styleTagsForType((string) $type);
        // Escape dynamic content so values like "<empty/null>" or stray angle
        // brackets aren't parsed as symfony/console style tags.
        $this->output->writeln($open . OutputFormatter::escape($message) . $close);
    }

    public function flushNowLine(): void
    {
        $this->flushNow('-------------------------------');
    }

    /**
     * Map the legacy DB::alteration_message() message "types" onto
     * symfony/console styling tags understood by PolyOutput.
     *
     * @return array{0:string,1:string} [openTag, closeTag]
     */
    protected function styleTagsForType(string $type): array
    {
        return match ($type) {
            'error', 'deleted' => ['<fg=red>', '</>'],
            'created', 'changed', 'repaired' => ['<fg=green>', '</>'],
            'notice' => ['<comment>', '</comment>'],
            default => ['', ''],
        };
    }

    // ----------------------------------------------------------------
    //                            Timing
    // ----------------------------------------------------------------

    protected function recordTiming(string $name, string $phase, float $seconds): void
    {
        $this->tableTimings[] = [
            'name' => $name,
            'phase' => $phase,
            'seconds' => $seconds,
        ];
    }

    protected function formatDuration(float $seconds): string
    {
        if ($seconds < 1) {
            return round($seconds * 1000) . 'ms';
        }
        if ($seconds < 60) {
            return round($seconds, 2) . 's';
        }
        $mins = (int) floor($seconds / 60);
        $secs = (int) round($seconds - ($mins * 60));
        return $mins . 'm ' . $secs . 's';
    }

    /**
     * Whether a column's SQL type can hold a class-name string.
     *
     * $columnType comes from SHOW COLUMNS, e.g. "varchar(255)", "text",
     * "enum('a','b')", "int", "datetime(6)", "decimal(10,2)".
     */
    protected function isTextColumn(string $columnType): bool
    {
        $baseType = strtolower(trim($columnType));
        $parenPos = strpos($baseType, '(');
        if ($parenPos !== false) {
            $baseType = substr($baseType, 0, $parenPos);
        }
        $baseType = trim($baseType);

        return in_array($baseType, [
            'char',
            'varchar',
            'tinytext',
            'text',
            'mediumtext',
            'longtext',
            'enum', // legacy ClassName columns were enums before being widened to varchar
            'set',
        ], true);
    }

    protected function reportSlowest(int $limit = 15): void
    {
        if (count($this->tableTimings) === 0) {
            return;
        }

        usort($this->tableTimings, fn ($a, $b) => $b['seconds'] <=> $a['seconds']);

        $this->flushNow('');
        $this->flushNowLine();
        $this->flushNow('Slowest tables (top ' . $limit . ')');
        $this->flushNowLine();

        foreach (array_slice($this->tableTimings, 0, $limit) as $t) {
            $this->flushNow(
                '... ' . str_pad($this->formatDuration($t['seconds']), 8)
                . ' [' . $t['phase'] . '] ' . $t['name']
            );
        }

        $total = array_sum(array_column($this->tableTimings, 'seconds'));
        $this->flushNow('... total measured: ' . $this->formatDuration($total));
    }

    protected function tableExists(string $tableName): bool
    {
        $schema = $this->getSchema();

        return (bool) $schema->hasTable($tableName);
    }

    protected function getSchema()
    {
        if (null === $this->_schema) {
            $this->_schema = DB::get_schema();
            $this->_schema->schemaUpdate(function () {
                return true;
            });
        }

        return $this->_schema;
    }

    private $_schema;

    protected function fieldExists(string $tableName, string $fieldName): bool
    {
        $sql = <<<'SQL'
                    SELECT 1
                    FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND COLUMN_NAME = ?
                    LIMIT 1
SQL;
        return (bool) DB::prepared_query($sql, [$tableName, $fieldName])->value();
    }
}
