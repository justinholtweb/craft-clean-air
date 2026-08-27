/* global Craft, $ */

/**
 * Clean Air — the filter builder.
 *
 * The form works without any of this: it's a plain HTML form, and submitting it runs the
 * filter. What this adds is the parts that would otherwise mean a page load each — swapping
 * the operator menu when you change field, fetching the right value input, and keeping a
 * live count of what the filter would match while you're still writing it.
 *
 * Value inputs are rendered by the server rather than built here, so a relation criterion
 * gets Craft's own element selector, with its sources and its search, instead of a
 * half-working imitation.
 */
(function () {
    'use strict';

    var CleanAir = function (container) {
        this.container = container;
        this.form = container.closest('form');
        this.typeKey = container.getAttribute('data-type-key');
        this.criteria = container.querySelector('[data-cleanair-criteria]');
        this.template = container.querySelector('[data-cleanair-row-template]');
        this.countEl = container.querySelector('[data-cleanair-count]');
        this.countAction = container.getAttribute('data-count-action');
        this.rowAction = container.getAttribute('data-row-action');
        this.nextIndex = parseInt(container.getAttribute('data-next-index') || '0', 10);
        this.countTimer = null;

        this.bind();
        this.refreshCount();
    };

    CleanAir.prototype.bind = function () {
        var self = this;

        this.container.addEventListener('click', function (event) {
            var add = event.target.closest('[data-cleanair-add]');
            if (add) {
                event.preventDefault();
                self.addRow();
                return;
            }

            var remove = event.target.closest('[data-cleanair-remove]');
            if (remove) {
                event.preventDefault();
                self.removeRow(remove.closest('[data-cleanair-criterion]'));
            }
        });

        this.container.addEventListener('change', function (event) {
            var target = event.target;

            if (target.matches('[data-cleanair-field]')) {
                self.reloadRow(target.closest('[data-cleanair-criterion]'), true);
                return;
            }

            if (target.matches('[data-cleanair-operator]')) {
                self.reloadRow(target.closest('[data-cleanair-criterion]'), false);
                return;
            }

            if (target.matches('[data-cleanair-source]')) {
                // The available fields depend on both, so the simplest correct thing is to
                // let the server rebuild the page rather than patch every row in place.
                self.form.querySelector('[name="run"]').value = '';
                self.form.submit();
                return;
            }

            self.queueCount();
        });

        this.container.addEventListener('input', function (event) {
            if (event.target.matches('input, textarea')) {
                self.queueCount();
            }
        });
    };

    CleanAir.prototype.addRow = function () {
        if (!this.template) {
            return;
        }

        var html = this.template.innerHTML.replace(/__INDEX__/g, String(this.nextIndex));
        var wrapper = document.createElement('div');
        wrapper.innerHTML = html.trim();

        var row = wrapper.firstElementChild;
        this.criteria.appendChild(row);
        this.nextIndex++;

        Craft.initUiElements($(row));
    };

    CleanAir.prototype.removeRow = function (row) {
        if (!row) {
            return;
        }

        row.parentNode.removeChild(row);
        this.queueCount();
    };

    /**
     * Fetches the operator menu and value input for one row. `resetOperator` is true when the
     * field changed, because the operator that was selected may not apply to the new field —
     * "is assigned" means nothing on a date.
     */
    CleanAir.prototype.reloadRow = function (row, resetOperator) {
        if (!row) {
            return;
        }

        var self = this;
        var index = row.getAttribute('data-index');
        var fieldEl = row.querySelector('[data-cleanair-field]');
        var operatorEl = row.querySelector('[data-cleanair-operator]');
        var target = row.querySelector('[data-cleanair-inputs]');

        if (!fieldEl || !target) {
            return;
        }

        row.classList.add('cleanair-loading');

        var data = {
            typeKey: this.typeKey,
            source: this.sourceValue(),
            index: index,
            field: fieldEl.value,
            operator: resetOperator || !operatorEl ? '' : operatorEl.value
        };

        Craft.sendActionRequest('POST', this.rowAction, {data: data})
            .then(function (response) {
                target.innerHTML = response.data.html;

                if (response.data.headHtml) {
                    Craft.appendHeadHtml(response.data.headHtml);
                }
                if (response.data.bodyHtml) {
                    Craft.appendBodyHtml(response.data.bodyHtml);
                }

                Craft.initUiElements($(target));
                self.queueCount();
            })
            .catch(function () {
                target.innerHTML = '';
            })
            .finally(function () {
                row.classList.remove('cleanair-loading');
            });
    };

    CleanAir.prototype.sourceValue = function () {
        var el = this.container.querySelector('[data-cleanair-source]');
        return el ? el.value : '';
    };

    CleanAir.prototype.queueCount = function () {
        var self = this;

        if (this.countTimer) {
            window.clearTimeout(this.countTimer);
        }

        this.countTimer = window.setTimeout(function () {
            self.refreshCount();
        }, 500);
    };

    CleanAir.prototype.refreshCount = function () {
        if (!this.countEl || !this.countAction || !this.form) {
            return;
        }

        var self = this;
        var formData = new FormData(this.form);

        // `run` is what tells the page to render results; the count endpoint doesn't want it.
        formData.delete('run');

        this.countEl.classList.add('cleanair-loading');

        Craft.sendActionRequest('POST', this.countAction, {data: formData})
            .then(function (response) {
                var count = response.data.count;
                self.countEl.innerHTML = Craft.t('cleanair', '{count} matching', {
                    count: '<strong>' + Craft.formatNumber(count) + '</strong>'
                });
            })
            .catch(function () {
                self.countEl.textContent = '';
            })
            .finally(function () {
                self.countEl.classList.remove('cleanair-loading');
            });
    };

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-cleanair-builder]').forEach(function (el) {
            new CleanAir(el);
        });

        var toggle = document.querySelector('[data-cleanair-columns-toggle]');
        var panel = document.querySelector('[data-cleanair-columns]');

        if (toggle && panel) {
            toggle.addEventListener('click', function (event) {
                event.preventDefault();
                panel.classList.toggle('hidden');
            });
        }
    });
})();
