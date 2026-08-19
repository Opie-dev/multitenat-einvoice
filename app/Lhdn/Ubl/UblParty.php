<?php

namespace App\Lhdn\Ubl;

use App\Models\Issuer;

final class UblParty
{
    /** @return array<string, mixed> */
    public static function supplier(Issuer $issuer): array
    {
        $ids = [
            ['ID' => [['_' => $issuer->tin, 'schemeID' => 'TIN']]],
            ['ID' => [['_' => $issuer->id_number, 'schemeID' => $issuer->id_type->value]]],
        ];
        if ($issuer->sst_number !== null && $issuer->sst_number !== '') {
            $ids[] = ['ID' => [['_' => $issuer->sst_number, 'schemeID' => 'SST']]];
        }
        if ($issuer->tourism_tax_number !== null && $issuer->tourism_tax_number !== '') {
            $ids[] = ['ID' => [['_' => $issuer->tourism_tax_number, 'schemeID' => 'TTX']]];
        }

        return ['Party' => [[
            'IndustryClassificationCode' => [['_' => $issuer->msic_code, 'name' => $issuer->business_activity_description]],
            'PartyIdentification' => $ids,
            'PostalAddress' => [self::address($issuer->city, $issuer->postcode, $issuer->state_code, array_values(array_filter([$issuer->address_line1, $issuer->address_line2, $issuer->address_line3], fn ($l) => $l !== null && $l !== '')), $issuer->country_code)],
            'PartyLegalEntity' => [['RegistrationName' => [['_' => $issuer->name]]]],
            'Contact' => [['Telephone' => [['_' => $issuer->phone]], 'ElectronicMail' => [['_' => $issuer->email]]]],
        ]]];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public static function buyer(array $snapshot): array
    {
        $general = (bool) ($snapshot['general_public'] ?? false);
        $tin = (string) ($snapshot['tin'] ?? ($general ? 'EI00000000010' : 'NA'));
        $idType = (string) ($snapshot['id_type'] ?? 'BRN');
        $idNumber = (string) ($snapshot['id_number'] ?? 'NA');
        $ids = [
            ['ID' => [['_' => $tin, 'schemeID' => 'TIN']]],
            ['ID' => [['_' => $idNumber, 'schemeID' => $idType]]],
        ];
        if (! empty($snapshot['sst_number'])) {
            $ids[] = ['ID' => [['_' => (string) $snapshot['sst_number'], 'schemeID' => 'SST']]];
        }
        $lines = array_values(array_filter([$snapshot['address_line1'] ?? null, $snapshot['address_line2'] ?? null, $snapshot['address_line3'] ?? null], fn ($l) => $l !== null && $l !== ''));
        $contact = ['Telephone' => [['_' => (string) ($snapshot['phone'] ?? 'NA')]]];
        if (! empty($snapshot['email'])) {
            $contact['ElectronicMail'] = [['_' => (string) $snapshot['email']]];
        }

        return ['Party' => [[
            'PartyIdentification' => $ids,
            'PostalAddress' => [self::address((string) ($snapshot['city'] ?? 'NA'), (string) ($snapshot['postcode'] ?? 'NA'), (string) ($snapshot['state_code'] ?? '17'), $lines === [] ? ['NA'] : $lines, (string) ($snapshot['country_code'] ?? 'MYS'))],
            'PartyLegalEntity' => [['RegistrationName' => [['_' => (string) ($snapshot['name'] ?? 'General Public')]]]],
            'Contact' => [$contact],
        ]]];
    }

    /**
     * @param  list<string>  $lines
     * @return array<string, mixed>
     */
    private static function address(string $city, string $postcode, string $state, array $lines, string $country): array
    {
        return [
            'CityName' => [['_' => $city]],
            'PostalZone' => [['_' => $postcode]],
            'CountrySubentityCode' => [['_' => $state]],
            'AddressLine' => array_map(fn (string $l) => ['Line' => [['_' => $l]]], $lines),
            'Country' => [['IdentificationCode' => [['_' => $country, 'listID' => 'ISO3166-1', 'listAgencyID' => '6']]]],
        ];
    }
}
