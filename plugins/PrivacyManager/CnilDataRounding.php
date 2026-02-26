<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\PrivacyManager;

use Piwik\Columns\Dimension;
use Piwik\Common;
use Piwik\Container\StaticContainer;
use Piwik\DataTable;
use Piwik\DataTable\DataTableInterface;
use Piwik\Plugin\Metric;
use Piwik\Plugin\Report;
use Piwik\Plugins\FeatureFlags\FeatureFlagManager;
use Piwik\Plugins\PrivacyManager\FeatureFlags\PrivacyCompliance;
use Piwik\Policy\CnilPolicy;
use Piwik\Policy\PolicyManager;
use Throwable;

class CnilDataRounding
{
    // PoC variable that can be flipped to disable CNIL count rounding globally.
    public static $isPocEnabled = true;

    public static function shouldApplyForRequest(array $request): bool
    {
        if (!self::$isPocEnabled || !self::hasSegment($request)) {
            return false;
        }

        return self::isCnilPolicyActive(self::extractSiteId($request));
    }

    public static function roundCountMetrics(DataTableInterface $dataTable, ?Report $report = null): void
    {
        $dataTable->filter(function (DataTable $table) use ($report) {
            self::roundDataTable($table, $report);
        });
    }

    private static function roundDataTable(DataTable $table, ?Report $report = null): void
    {
        $metricTypes = self::getMetricTypes($table, $report);
        $columnsToRound = self::getColumnsToRound($table, $metricTypes);

        if (!empty($columnsToRound)) {
            foreach ($table->getRows() as $row) {
                foreach ($columnsToRound as $columnName) {
                    $value = $row->getColumn($columnName);
                    if (self::shouldRoundValue($value)) {
                        $row->setColumn($columnName, self::roundToNearestTen((float) $value));
                    }
                }

                $comparisons = $row->getComparisons();
                if (!empty($comparisons)) {
                    self::roundDataTable($comparisons, $report);
                }
            }
        }

        $totals = $table->getMetadata('totals');
        if (is_array($totals)) {
            $table->setMetadata('totals', self::roundTotals($totals, $metricTypes));
        }
    }

    /**
     * @return array<string, string|null>
     */
    private static function getMetricTypes(DataTable $table, ?Report $report = null): array
    {
        $metricTypes = [];

        if (!empty($report)) {
            $metricTypes = $report->getMetricSemanticTypes();
        }

        $metrics = Report::getMetricsForTable($table, $report, Metric::class);
        foreach ($metrics as $metric) {
            $name = $metric->getName();
            $metricTypes[$name] = $metric->getSemanticType() ?: ($metricTypes[$name] ?? null);
        }

        return $metricTypes;
    }

    /**
     * @param array<string, string|null> $metricTypes
     * @return string[]
     */
    private static function getColumnsToRound(DataTable $table, array $metricTypes): array
    {
        $firstRow = $table->getFirstRow();
        if (empty($firstRow)) {
            return [];
        }

        $columns = [];
        foreach ($firstRow->getColumns() as $columnName => $value) {
            if (!self::shouldRoundColumn((string) $columnName, $metricTypes[(string) $columnName] ?? null)) {
                continue;
            }

            if (self::shouldRoundValue($value)) {
                $columns[] = (string) $columnName;
            }
        }

        return $columns;
    }

    /**
     * @param array<string, mixed> $totals
     * @param array<string, string|null> $metricTypes
     * @return array<string, mixed>
     */
    private static function roundTotals(array $totals, array $metricTypes): array
    {
        foreach ($totals as $columnName => $value) {
            if (is_array($value)) {
                $totals[$columnName] = self::roundTotals($value, $metricTypes);
                continue;
            }

            if (
                self::shouldRoundColumn((string) $columnName, $metricTypes[(string) $columnName] ?? null)
                && self::shouldRoundValue($value)
            ) {
                $totals[$columnName] = self::roundToNearestTen((float) $value);
            }
        }

        return $totals;
    }

    private static function shouldRoundValue($value): bool
    {
        return is_numeric($value) && $value >= 0;
    }

    private static function roundToNearestTen(float $value): int
    {
        if ($value === 0.0) {
            return 0;
        }

        return max(10, (int) (floor(($value + 5) / 10) * 10));
    }

    private static function shouldRoundColumn(string $columnName, ?string $semanticType): bool
    {
        if ($columnName === '' || $columnName === 'label' || preg_match('/_change$/i', $columnName)) {
            return false;
        }

        if (!empty($semanticType)) {
            if ($semanticType === Dimension::TYPE_NUMBER) {
                return true;
            }

            if (in_array($semanticType, [
                Dimension::TYPE_PERCENT,
                Dimension::TYPE_DURATION_MS,
                Dimension::TYPE_DURATION_S,
                Dimension::TYPE_MONEY,
                Dimension::TYPE_FLOAT,
                Dimension::TYPE_TIME,
                Dimension::TYPE_DATE,
                Dimension::TYPE_DATETIME,
                Dimension::TYPE_TIMESTAMP,
                Dimension::TYPE_URL,
                Dimension::TYPE_TEXT,
                Dimension::TYPE_ENUM,
                Dimension::TYPE_BOOL,
                Dimension::TYPE_BINARY,
                Dimension::TYPE_BYTE,
                Dimension::TYPE_DIMENSION,
            ], true)) {
                return false;
            }
        }

        $columnName = strtolower($columnName);

        if (preg_match('/(rate|percent|percentage|revenue|price|cost|tax|shipping|discount|avg_|average|time|duration|evolution|min_|max_)/', $columnName)) {
            return false;
        }

        return (bool) preg_match('/(^nb_|_nb_|_count$|^count_|^sum_daily_nb_|^hits$|^visits$|^actions$|^conversions$|^users$|^goals$|^orders$|^items$|^quantity$|^impressions$|^interactions$|^downloads$|^outlinks$|^bounce_count$|^entry_nb_|^exit_nb_)/', $columnName);
    }

    private static function hasSegment(array $request): bool
    {
        try {
            $segment = Common::getRequestVar('segment', '', 'string', $request);
            return trim($segment) !== '';
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function extractSiteId(array $request): ?int
    {
        try {
            $idSite = Common::getRequestVar('idSite', null, null, $request);
        } catch (Throwable $e) {
            return null;
        }

        if ($idSite === 'all' || $idSite === null || $idSite === '') {
            return null;
        }

        if (is_numeric($idSite)) {
            $idSite = (int) $idSite;
            return $idSite > 0 ? $idSite : null;
        }

        return null;
    }

    private static function isCnilPolicyActive(?int $idSite): bool
    {
        try {
            $featureFlagManager = StaticContainer::get(FeatureFlagManager::class);
            if (!$featureFlagManager->isFeatureActive(PrivacyCompliance::class)) {
                return false;
            }

            return PolicyManager::isPolicyActive(CnilPolicy::class, $idSite);
        } catch (Throwable $e) {
            return false;
        }
    }
}
