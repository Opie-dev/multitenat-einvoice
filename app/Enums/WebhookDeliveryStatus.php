<?php

namespace App\Enums;

enum WebhookDeliveryStatus: string
{
    case Pending = 'pending';
    case Retrying = 'retrying';
    case Delivered = 'delivered';
    case Exhausted = 'exhausted';
}
