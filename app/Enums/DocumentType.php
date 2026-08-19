<?php

namespace App\Enums;

enum DocumentType: string
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
    case DebitNote = 'debit_note';
    case RefundNote = 'refund_note';
    case SelfBilledInvoice = 'self_billed_invoice';
    case SelfBilledCreditNote = 'self_billed_credit_note';
    case SelfBilledDebitNote = 'self_billed_debit_note';
    case SelfBilledRefundNote = 'self_billed_refund_note';

    public function lhdnCode(): string
    {
        return match ($this) {
            self::Invoice => '01',
            self::CreditNote => '02',
            self::DebitNote => '03',
            self::RefundNote => '04',
            self::SelfBilledInvoice => '11',
            self::SelfBilledCreditNote => '12',
            self::SelfBilledDebitNote => '13',
            self::SelfBilledRefundNote => '14',
        };
    }

    public static function fromLhdnCode(string $code): self
    {
        foreach (self::cases() as $case) {
            if ($case->lhdnCode() === $code) {
                return $case;
            }
        }
        throw new \ValueError("Unknown LHDN document type code {$code}");
    }

    public function isSelfBilled(): bool
    {
        return str_starts_with($this->value, 'self_billed_');
    }

    public function requiresOriginalRef(): bool
    {
        return str_ends_with($this->value, '_note');
    }
}
