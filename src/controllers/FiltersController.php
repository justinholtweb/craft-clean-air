<?php

namespace justinholtweb\cleanair\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\View;
use justinholtweb\cleanair\models\Edition;
use justinholtweb\cleanair\models\FilterSet;
use justinholtweb\cleanair\models\SavedFilter;
use justinholtweb\cleanair\Plugin;
use justinholtweb\cleanair\records\ExportRecord;
use justinholtweb\cleanair\types\BaseType;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The filter builder and its results.
 *
 * The whole filter travels in the query string, so a result set is a URL — paste it to a
 * colleague, bookmark it, put it in a ticket. That's the difference between a tool you use
 * and a tool you use *with* people, and it's why the builder is a plain form with progressive
 * enhancement rather than an app that keeps its state to itself.
 */
class FiltersController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (!Plugin::getInstance()->permissions->canView()) {
            throw new ForbiddenHttpException(Craft::t('cleanair', 'You don’t have permission to use Clean Air.'));
        }

        return true;
    }

    /**
     * The builder, and the results if the filter has been run.
     */
    public function actionIndex(?string $typeKey = null): Response
    {
        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();

        $available = $plugin->types->available();

        if (!$available) {
            return $this->renderTemplate('cleanair/_empty', [
                'reason' => Craft::t('cleanair', 'There are no element types you can filter.'),
            ]);
        }

        $filter = $this->filterFromRequest();
        $type = $this->resolveType($filter, $typeKey, $available);
        $filter->elementType = $type->elementType();

        // A source the user isn't allowed to use is dropped rather than honoured, so an
        // edited URL can't reach past the settings or their permissions.
        if ($filter->source !== null && !$plugin->types->isAllowedSource($type, $filter->source)) {
            $filter->source = null;
        }

        $sources = $plugin->types->sources($type);
        $definitions = $plugin->fields->definitions($type, $filter->source);
        $ran = (bool)$request->getParam('run');

        $results = null;

        if ($ran) {
            $results = $plugin->filters->results($filter, (int)$request->getParam('page', 1));
        }

        return $this->renderTemplate('cleanair/index', [
            'plugin' => $plugin,
            'types' => $available,
            'type' => $type,
            'filter' => $filter,
            'sources' => $sources,
            'definitions' => $definitions,
            'grouped' => $plugin->fields->grouped($type, $filter->source),
            'rows' => $this->buildRows($type, $filter),
            'columnOptions' => $plugin->columns->available($type, $filter->source),
            'selectedColumns' => $plugin->columns->resolve($filter, $type),
            'filterId' => $request->getParam('filterId'),
            'results' => $results,
            'ran' => $ran,
            'savedFilters' => $plugin->savedFilters->all(null, $type->elementType()),
            'operators' => $plugin->operators,
            'isPro' => $plugin->isPro(),
            'canExport' => $plugin->permissions->canExport(),
            'canSave' => $plugin->permissions->canSave(),
            'canShare' => $plugin->permissions->canShare(),
            'shareUrl' => $ran ? $this->shareUrl($type, $filter) : null,
        ]);
    }

    /**
     * The operator menu and value input for a criterion, rendered server-side.
     *
     * Building these in JavaScript would mean reimplementing Craft's date pickers and element
     * selects; asking the server for them means a relation criterion gets the same element
     * selector as anywhere else in the control panel, with the same sources and the same
     * search.
     */
    public function actionCriterionRow(): Response
    {
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();

        $type = $plugin->types->forKey($request->getRequiredParam('typeKey'));

        if (!$type) {
            throw new NotFoundHttpException();
        }

        $source = $request->getParam('source') ?: null;
        $index = (int)$request->getParam('index', 0);
        $handle = (string)$request->getParam('field');
        $operator = (string)$request->getParam('operator');
        $value = $request->getParam('value');
        $value2 = $request->getParam('value2');

        $definition = $handle !== '' ? $plugin->fields->definition($type, $source, $handle) : null;
        $operators = $definition ? $plugin->operators->forField($definition) : [];

        if ($operators && !isset($operators[$operator])) {
            $operator = (string)array_key_first($operators);
        }

        return $this->asJson([
            // The control panel template mode is named explicitly. An action request posted to
            // `/index.php?action=…` rather than `/admin/actions/…` isn't a control panel
            // request, and the view would go looking for this in the site's templates.
            'html' => $this->getView()->renderTemplate('cleanair/_partials/criterion-inputs', [
                'definition' => $definition,
                'operators' => $operators,
                'operator' => $operator,
                'index' => $index,
                'value' => $value,
                'value2' => $value2,
                'dateValue' => $this->toDate($value),
                'dateValue2' => $this->toDate($value2),
                'valueElements' => $plugin->fields->elementsForValue($definition, $value),
            ], View::TEMPLATE_MODE_CP),
            'headHtml' => $this->getView()->getHeadHtml(),
            'bodyHtml' => $this->getView()->getBodyHtml(),
        ]);
    }

    /**
     * The field list for an element type and source, for when either changes.
     */
    public function actionFields(): Response
    {
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();

        $type = $plugin->types->forKey($request->getRequiredParam('typeKey'));

        if (!$type) {
            throw new NotFoundHttpException();
        }

        $source = $request->getParam('source') ?: null;
        $grouped = [];

        foreach ($plugin->fields->definitions($type, $source) as $definition) {
            $grouped[$definition->group][] = [
                'handle' => $definition->handle,
                'label' => $definition->label . ($definition->partial ? ' *' : ''),
            ];
        }

        return $this->asJson([
            'groups' => $grouped,
            'sources' => array_map(
                fn($source) => ['key' => $source->key, 'label' => $source->label, 'group' => $source->group, 'hint' => $source->hint],
                $plugin->types->sources($type),
            ),
        ]);
    }

    /**
     * Just the count, for the "how many will this match?" readout — cheap enough to run while
     * someone is still building the filter.
     */
    public function actionCount(): Response
    {
        $this->requireAcceptsJson();

        $filter = $this->filterFromRequest();
        $warnings = [];

        return $this->asJson([
            'count' => Plugin::getInstance()->filters->count($filter, $warnings),
            'warnings' => $warnings,
        ]);
    }

    /**
     * Exports the current result set — immediately for a small one, on the queue for a large
     * one, which is the difference between a report you can run and a request that times out.
     */
    public function actionExport(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();

        if (!$plugin->permissions->canExport()) {
            throw new ForbiddenHttpException(Craft::t('cleanair', 'You don’t have permission to export from Clean Air.'));
        }

        $request = Craft::$app->getRequest();
        $format = (string)$request->getParam('format', 'csv');

        if (!Edition::allowsFormat($plugin->isPro(), $format)) {
            throw new ForbiddenHttpException(Craft::t('cleanair', 'Clean Air Lite exports CSV. Upgrade to Pro for TSV, JSON and XML.'));
        }

        $filter = $this->filterFromRequest();
        $type = $plugin->filters->type($filter);
        $label = (string)($request->getParam('label') ?: $type->label());
        $userId = Craft::$app->getUser()->getId();

        $warnings = [];
        $count = $plugin->filters->count($filter, $warnings);

        Craft::info(sprintf(
            'Export of %d %s requested by user %s',
            $count,
            $type->key(),
            $userId ?? 'unknown',
        ), Plugin::LOG_CATEGORY);

        if ($plugin->exports->shouldQueue($count)) {
            $plugin->exports->queue($filter, $format, $label, $userId);

            $this->setSuccessFlash(Craft::t('cleanair', 'Export queued — {count} results. It’ll appear under Exports when it’s done.', ['count' => $count]));

            return $this->redirect(UrlHelper::cpUrl('cleanair/exports'));
        }

        $record = $plugin->exports->run($filter, $format, $label, $userId);

        return $this->sendExport($record);
    }

    /**
     * Saves the current filter.
     */
    public function actionSave(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();

        if (!$plugin->permissions->canSave()) {
            throw new ForbiddenHttpException(Craft::t('cleanair', 'You don’t have permission to save filters.'));
        }

        $request = Craft::$app->getRequest();
        $filterSet = $this->filterFromRequest();
        $id = $request->getParam('filterId');

        $saved = $id ? $plugin->savedFilters->getById((int)$id) : null;

        if ($saved && !$plugin->permissions->canWriteFilter($saved)) {
            throw new ForbiddenHttpException(Craft::t('cleanair', 'You don’t have permission to change that filter.'));
        }

        if (!$saved) {
            $saved = new SavedFilter(['userId' => Craft::$app->getUser()->getId()]);
        }

        $saved->name = (string)$request->getRequiredParam('name');
        $saved->elementType = $filterSet->elementType;
        $saved->source = $filterSet->source;
        $saved->filter = $filterSet;
        $saved->pinned = (bool)$request->getParam('pinned');

        $visibility = (string)$request->getParam('visibility', SavedFilter::VISIBILITY_PRIVATE);

        if ($visibility === SavedFilter::VISIBILITY_SHARED && !$plugin->permissions->canShare()) {
            $visibility = SavedFilter::VISIBILITY_PRIVATE;
        }

        $saved->visibility = $visibility;

        $errors = [];

        if (!$plugin->savedFilters->save($saved, $errors)) {
            $this->setFailFlash(implode(' ', $errors) ?: Craft::t('cleanair', 'Couldn’t save that filter.'));

            // Not `redirectToPostedUrl()` — with no redirect param that lands on the action
            // URL itself, which renders nothing.
            $type = $plugin->types->forElementType($filterSet->elementType);

            return $this->redirect(UrlHelper::cpUrl('cleanair/type/' . ($type?->key() ?? '')));
        }

        $this->setSuccessFlash(Craft::t('cleanair', 'Filter saved.'));

        return $this->redirect($saved->getCpUrl());
    }

    private function sendExport(ExportRecord $record): Response
    {
        if ($record->status !== ExportRecord::STATUS_DONE || !$record->path || !is_file($record->path)) {
            throw new NotFoundHttpException(Craft::t('cleanair', 'That export isn’t ready.'));
        }

        return Craft::$app->getResponse()->sendFile($record->path, $record->filename, [
            'mimeType' => match ($record->format) {
                'json' => 'application/json',
                'xml' => 'application/xml',
                'tsv' => 'text/tab-separated-values',
                default => 'text/csv',
            },
        ]);
    }

    /**
     * @param BaseType[] $available
     */
    private function resolveType(FilterSet $filter, ?string $typeKey, array $available): BaseType
    {
        $plugin = Plugin::getInstance();

        if ($typeKey !== null && isset($available[$typeKey])) {
            return $available[$typeKey];
        }

        $fromFilter = $plugin->types->forElementType($filter->elementType);

        if ($fromFilter && isset($available[$fromFilter->key()])) {
            return $fromFilter;
        }

        return reset($available);
    }

    /**
     * Reads a filter out of the request — query string for a shared link, body for a form
     * post. Nothing is trusted: the element type, the source and every criterion are checked
     * against what this user may see before any of it reaches a query.
     */
    private function filterFromRequest(): FilterSet
    {
        $request = Craft::$app->getRequest();
        $config = $request->getBodyParam('filter') ?? $request->getQueryParam('filter') ?? [];

        return FilterSet::fromArray(is_array($config) ? $config : []);
    }

    /**
     * Prepares each criterion for rendering: its definition, the operators that apply to it,
     * and the values already chosen — resolved into elements or dates where the input needs
     * them rather than left as raw IDs and strings.
     */
    private function buildRows(BaseType $type, FilterSet $filter): array
    {
        $plugin = Plugin::getInstance();
        $rows = [];

        foreach ($filter->criteria as $index => $criterion) {
            $definition = $criterion->field
                ? $plugin->fields->definition($type, $filter->source, $criterion->field)
                : null;

            $operators = $definition ? $plugin->operators->forField($definition) : [];
            $operator = (string)$criterion->operator;

            if ($operators && !isset($operators[$operator])) {
                $operator = (string)array_key_first($operators);
            }

            $rows[] = [
                'index' => $index,
                'criterion' => $criterion,
                'definition' => $definition,
                'operators' => $operators,
                'operator' => $operator,
                'value' => $criterion->value,
                'value2' => $criterion->value2,
                'dateValue' => $this->toDate($criterion->value),
                'dateValue2' => $this->toDate($criterion->value2),
                'valueElements' => $plugin->fields->elementsForValue($definition, $criterion->value),
            ];
        }

        return $rows;
    }

    /** Craft's date input wants a DateTime, not whatever the criterion happens to hold. */
    private function toDate(mixed $value): ?\DateTime
    {
        if ($value === null || $value === '' || (is_array($value) && ($value['date'] ?? '') === '')) {
            return null;
        }

        $date = \craft\helpers\DateTimeHelper::toDateTime($value, true, true);

        return $date === false ? null : $date;
    }

    private function shareUrl(BaseType $type, FilterSet $filter): ?string
    {
        if (!Plugin::getInstance()->getSettings()->shareableUrls) {
            return null;
        }

        return UrlHelper::cpUrl('cleanair/type/' . $type->key(), [
            'filter' => $filter->toArray(),
            'run' => 1,
        ]);
    }
}
