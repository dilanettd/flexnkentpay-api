<?php

namespace App\Enums;

enum PawaPayTransactionStatus: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case REJECTED = 'rejected';
    case DUPLICATE_IGNORED = 'duplicate_ignored';
}

enum PawaPayTransactionType: string
{
    case DEPOSIT = 'deposit';
    case PAYOUT = 'payout';
    case REFUND = 'refund';
}

enum TransactionTypes: string
{
    case TOPUP = 'topup';
    case WITHDRAWAL = 'withdrawal';
    case REFUND_TRANSACTION = 'refund_transaction';
}

enum ProviderType: string
{
    case PAWAPAY = 'pawapay';
}