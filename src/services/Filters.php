<?php

namespace justinholtweb\cleanair\services;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use Generator;
use justinholtweb\cleanair\models\FilterSet;
use justinholtweb\cleanair\Plugin;
use justinholtweb\cleanair\types\BaseType;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Running a filter.
 *
 * The query builder does the thinking; this is the part that hands back a page of results, a
 * count, or a stream — and it is the one place that decides how many rows anybody gets, so a
 * filter can't be talked into loading a whole site into memory from three different callers.
 */
class Filters extends Component
{
    /**
     * A page of results.
     *
     * @return array{elements: ElementInterface[], total: int, columns: string[], warnings: string[], page: int, pageCount: int, perPage: int}
     */
    public function results(FilterSet $filter, int $page = 1, ?int $perPage = null): array
    {
        $type = $this->type($filter);
        $perPage = max(1, $perPage ?? Plugin::getInstance()->getSettings()->resultsPerPage);
        $page = max(1, $page);

        $warnings = [];
        $query = Plugin::getInstance()->query->build($filter, $warnings);

        $total = (int)$query->count();
        $pageCount = max(1, (int)ceil($total / $perPage));
        $page = min($page, $pageCount);

        $columns = Plugin::getInstance()->columns->resolve($filter, $type);
        Plugin::getInstance()->columns->eagerLoad($query, $type, $filter->source, $columns);

        $elements = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->all();

        return [
            'elements' => $elements,
            'total' => $total,
            'columns' => $columns,
            'warnings' => $warnings,
            'page' => $page,
            'pageCount' => $pageCount,
            'perPage' => $perPage,
        ];
    }

    public function count(FilterSet $filter, array &$warnings = []): int
    {
        return (int)Plugin::getInstance()->query->build($filter, $warnings)->count();
    }

    public function query(FilterSet $filter, array &$warnings = []): ElementQueryInterface
    {
        return Plugin::getInstance()->query->build($filter, $warnings);
    }

    /**
     * Every matching element, a batch at a time.
     *
     * Two strategies, because they trade off against each other. When the filter has no sort
     * of its own, walking by ascending ID is used: it stays fast on the four-hundred-thousandth
     * row and doesn't skip or repeat rows if something is edited mid-export. When the user
     * *has* chosen a sort, that sort is honoured and the walk falls back to offsets — a slower
     * read on very large sets, but an export that silently ignored the sort you picked would
     * be worse.
     *
     * @return Generator<ElementInterface>
     */
    public function each(FilterSet $filter, ?int $batchSize = null, ?int $max = null): Generator
    {
        $settings = Plugin::getInstance()->getSettings();
        $batchSize = max(1, $batchSize ?? $settings->exportBatchSize);
        $keyset = $filter->orderBy === null;

        $warnings = [];
        $type = $this->type($filter);
        $columns = Plugin::getInstance()->columns->resolve($filter, $type);

        $lastId = 0;
        $offset = 0;
        $yielded = 0;

        while (true) {
            $query = Plugin::getInstance()->query->build($filter, $warnings);
            Plugin::getInstance()->columns->eagerLoad($query, $type, $filter->source, $columns);

            if ($keyset) {
                $query
                    ->andWhere(['>', 'elements.id', $lastId])
                    ->orderBy(['elements.id' => SORT_ASC])
                    ->offset(0)
                    ->limit($batchSize);
            } else {
                $query->offset($offset)->limit($batchSize);
            }

            $elements = $query->all();

            if (!$elements) {
                return;
            }

            foreach ($elements as $element) {
                yield $element;
                $lastId = max($lastId, (int)$element->id);
                $yielded++;

                if ($max !== null && $yielded >= $max) {
                    return;
                }
            }

            $offset += count($elements);

            if (count($elements) < $batchSize) {
                return;
            }
        }
    }

    public function type(FilterSet $filter): BaseType
    {
        $type = Plugin::getInstance()->types->forElementType($filter->elementType);

        if (!$type) {
            throw new InvalidArgumentException(Craft::t('cleanair', 'Clean Air can’t filter {type}.', ['type' => $filter->elementType]));
        }

        return $type;
    }
}
