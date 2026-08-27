<?php

namespace justinholtweb\cleanair\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\cleanair\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Saved filters: the list, and running one.
 */
class SavedFiltersController extends Controller
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

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $currentUser = Craft::$app->getUser()->getIdentity();

        return $this->renderTemplate('cleanair/saved/index', [
            'plugin' => $plugin,
            'filters' => $plugin->savedFilters->all(),
            'currentUser' => $currentUser,
            'canShare' => $plugin->permissions->canShare(),
            'isPro' => $plugin->isPro(),
        ]);
    }

    /**
     * Runs a saved filter by loading it back into the builder, so the results and the filter
     * that produced them are on the same screen and the filter can be adjusted from there.
     */
    public function actionRun(int $filterId): Response
    {
        $plugin = Plugin::getInstance();
        $saved = $plugin->savedFilters->getById($filterId);

        if (!$saved) {
            throw new NotFoundHttpException();
        }

        if (!$plugin->permissions->canReadFilter($saved)) {
            throw new ForbiddenHttpException(Craft::t('cleanair', 'You don’t have permission to view that filter.'));
        }

        $plugin->savedFilters->touch($saved->id);

        $type = $plugin->types->forElementType($saved->elementType);

        if (!$type) {
            $this->setFailFlash(Craft::t('cleanair', 'That filter is for an element type Clean Air can no longer see.'));
            return $this->redirect(UrlHelper::cpUrl('cleanair/saved'));
        }

        return $this->redirect(UrlHelper::cpUrl('cleanair/type/' . $type->key(), [
            'filter' => $saved->filter->toArray(),
            'filterId' => $saved->id,
            'run' => 1,
        ]));
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
        $saved = $plugin->savedFilters->getById($id);

        if (!$saved) {
            throw new NotFoundHttpException();
        }

        if (!$plugin->permissions->canWriteFilter($saved)) {
            throw new ForbiddenHttpException(Craft::t('cleanair', 'You don’t have permission to delete that filter.'));
        }

        $plugin->savedFilters->delete($id);
        $this->setSuccessFlash(Craft::t('cleanair', 'Filter deleted.'));

        return $this->redirect(UrlHelper::cpUrl('cleanair/saved'));
    }

    public function actionDuplicate(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();

        if (!$plugin->permissions->canSave()) {
            throw new ForbiddenHttpException(Craft::t('cleanair', 'You don’t have permission to save filters.'));
        }

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
        $saved = $plugin->savedFilters->getById($id);

        if (!$saved) {
            throw new NotFoundHttpException();
        }

        if (!$plugin->permissions->canReadFilter($saved)) {
            throw new ForbiddenHttpException();
        }

        $copy = $plugin->savedFilters->duplicate($saved);
        $errors = [];

        if (!$plugin->savedFilters->save($copy, $errors)) {
            $this->setFailFlash(implode(' ', $errors) ?: Craft::t('cleanair', 'Couldn’t duplicate that filter.'));
        } else {
            $this->setSuccessFlash(Craft::t('cleanair', 'Filter duplicated.'));
        }

        return $this->redirect(UrlHelper::cpUrl('cleanair/saved'));
    }

    /** Pinning puts a filter in Clean Air's sidebar. */
    public function actionTogglePin(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
        $saved = $plugin->savedFilters->getById($id);

        if (!$saved) {
            throw new NotFoundHttpException();
        }

        if (!$plugin->permissions->canWriteFilter($saved)) {
            throw new ForbiddenHttpException();
        }

        $saved->pinned = !$saved->pinned;
        $errors = [];
        $plugin->savedFilters->save($saved, $errors);

        return $this->redirect(UrlHelper::cpUrl('cleanair/saved'));
    }
}
