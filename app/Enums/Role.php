<?php

namespace App\Enums;

enum Role: string
{
    case AgencyOwner = 'agency_owner';
    case BusinessOwner = 'business_owner';
    case BusinessManager = 'business_manager';
    case SalesAgent = 'sales_agent';
    case MarketingManager = 'marketing_manager';
    case Analyst = 'analyst';

    public function canWriteCrm(): bool
    {
        return in_array($this, [self::AgencyOwner, self::BusinessOwner, self::BusinessManager, self::SalesAgent], true);
    }

    public function canExport(): bool
    {
        return in_array($this, [self::AgencyOwner, self::BusinessOwner, self::BusinessManager, self::MarketingManager], true);
    }

    public function label(): string
    {
        return $this === self::Analyst ? 'Read-only Analyst' : ucwords(str_replace('_', ' ', $this->value));
    }
}
