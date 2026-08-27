<?php

namespace justinholtweb\cleanair\types;

use Craft;
use craft\elements\Address;
use craft\elements\User;
use justinholtweb\cleanair\models\FieldDefinition;

/**
 * Addresses.
 *
 * Craft has no address element index at all, so before Clean Air the only way to answer
 * "which customers are in Ontario?" was a query in a template. The address query's own
 * parameters are typed `?string`, which rules out the compound operators, so every attribute
 * here is filtered against its column instead — same operators as everything else.
 *
 * Only columns the installed Craft version actually has are offered; the address schema has
 * grown across 5.x releases.
 */
class AddressType extends BaseType
{
    public function key(): string
    {
        return 'addresses';
    }

    public function elementType(): string
    {
        return Address::class;
    }

    public function requiresSource(): bool
    {
        return false;
    }

    public function nativeAttributes(): array
    {
        $labels = [
            'fullName' => Craft::t('app', 'Full Name'),
            'firstName' => Craft::t('app', 'First Name'),
            'lastName' => Craft::t('app', 'Last Name'),
            'organization' => Craft::t('app', 'Organization'),
            'organizationTaxId' => Craft::t('app', 'Organization Tax ID'),
            'addressLine1' => Craft::t('app', 'Address Line 1'),
            'addressLine2' => Craft::t('app', 'Address Line 2'),
            'addressLine3' => Craft::t('app', 'Address Line 3'),
            'locality' => Craft::t('app', 'Locality'),
            'dependentLocality' => Craft::t('app', 'Dependent Locality'),
            'administrativeArea' => Craft::t('app', 'Administrative Area'),
            'postalCode' => Craft::t('app', 'Postal Code'),
            'sortingCode' => Craft::t('app', 'Sorting Code'),
        ];

        $schema = Craft::$app->getDb()->getTableSchema('{{%addresses}}');
        $columns = $schema ? array_keys($schema->columns) : [];

        $definitions = [];
        foreach ($labels as $handle => $label) {
            if (in_array($handle, $columns, true)) {
                $definitions[] = $this->text($handle, $label, "addresses.$handle");
            }
        }

        if (in_array('countryCode', $columns, true)) {
            $countries = [];
            foreach (Craft::$app->getAddresses()->getCountryRepository()->getList() as $code => $name) {
                $countries[$code] = $name;
            }
            $definitions[] = $this->options('countryCode', Craft::t('app', 'Country'), $countries, 'addresses.countryCode');
        }

        if (in_array('ownerId', $columns, true)) {
            $definitions[] = new FieldDefinition([
                'handle' => 'ownerId',
                'label' => Craft::t('cleanair', 'Owner'),
                'kind' => FieldDefinition::KIND_NUMBER,
                'group' => 'Attributes',
                'native' => true,
                'column' => 'addresses.ownerId',
                'sortable' => true,
            ]);
        }

        return $definitions;
    }

    public function defaultColumns(): array
    {
        return ['id', 'title', 'fullName', 'locality', 'administrativeArea', 'countryCode'];
    }

    public function canView(?User $user): bool
    {
        return $user !== null && ($user->admin || $user->can('viewUsers'));
    }
}
