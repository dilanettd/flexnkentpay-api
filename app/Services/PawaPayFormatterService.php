<?php

namespace App\Services;

class PawaPayFormatterService
{
    const CORRESPONDENT_MTN_CMR = 'MTN_MOMO_CMR';
    const CORRESPONDENT_ORANGE_CMR = 'ORANGE_CMR';

    /**
     * Formate un numéro de téléphone et détermine l'opérateur
     */
    public function formatPhoneNumber(string $phoneNumber)
    {
        $phoneNumber = preg_replace('/[\s\-\(\)]/', '', $phoneNumber);
        $phoneNumber = ltrim($phoneNumber, '+');

        if (preg_match('/^6\d{8}$/', $phoneNumber)) {
            $phoneNumber = '237' . $phoneNumber;
        }

        $correspondent = null;

        $mtnPrefixes = [
            '23767',
            '237680',
            '237681',
            '237682',
            '237683',
            '237684',
            '237650',
            '237651',
            '237652',
            '237653',
            '237654'
        ];

        $orangePrefixes = [
            '23769',
            '237655',
            '237656',
            '237657',
            '237658',
            '237659',
            '237685',
            '237686',
            '237687',
            '237688',
            '237689',
            '237640'
        ];

        foreach ($mtnPrefixes as $prefix) {
            if (strpos($phoneNumber, $prefix) === 0) {
                $correspondent = self::CORRESPONDENT_MTN_CMR;
                break;
            }
        }

        if (!$correspondent) {
            foreach ($orangePrefixes as $prefix) {
                if (strpos($phoneNumber, $prefix) === 0) {
                    $correspondent = self::CORRESPONDENT_ORANGE_CMR;
                    break;
                }
            }
        }

        if (!$correspondent && strlen($phoneNumber) >= 4) {
            $firstDigit = substr($phoneNumber, 3, 1);
            if ($firstDigit === '6') {
                $correspondent = self::CORRESPONDENT_MTN_CMR;
            } else if ($firstDigit === '9') {
                $correspondent = self::CORRESPONDENT_ORANGE_CMR;
            }
        }

        if (!$correspondent) {
            $correspondent = self::CORRESPONDENT_MTN_CMR;
        }

        return [
            'phoneNumber' => $phoneNumber,
            'correspondent' => $correspondent
        ];
    }

    /**
     * Formate un montant pour l'API PawaPay
     */
    public function formatAmount(float $amount)
    {
        if (floor($amount) == $amount) {
            return (string) intval($amount);
        }

        return number_format($amount, 0, '', '');
    }

    /**
     * Formate les métadonnées pour l'API PawaPay
     */
    public function formatMetadata(array $metadata)
    {
        $formattedMetadata = [];
        foreach ($metadata as $key => $value) {
            $formattedMetadata[] = [
                'fieldName' => $key,
                'fieldValue' => (string) $value,
                'isPII' => false
            ];
        }

        return $formattedMetadata;
    }
}