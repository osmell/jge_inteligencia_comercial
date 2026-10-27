<?php

declare(strict_types=1);

namespace App\Imports;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Seller;
use App\Models\User;
use App\Services\DataScope;

/** Resuelve las columnas «Compañía» y «Sucursal» de una fila contra los catálogos, respetando el ámbito de quien importa. */
final class LocationResolver
{
    /** @var array<string, Company>|null */
    private ?array $companies = null;

    /** @var array<int, array<string, Branch>> */
    private array $branches = [];

    public function __construct(private readonly ?User $user, private readonly DataScope $scope) {}

    /** @return array{0: Company|null, 1: Branch|null} */
    public function resolve(ImportRow $row, string $companyKey = 'company', string $branchKey = 'branch'): array
    {
        $companyText = $row->text($companyKey);
        $branchText = $row->text($branchKey);
        if ($companyText === null || $branchText === null) {
            return [null, null];
        }

        $company = $this->companies()[Seller::normalize($companyText)] ?? null;
        if (! $company) {
            $row->fail($companyKey, 'unknown_company', "La compañía «{$companyText}» no existe (se busca por código o nombre).");

            return [null, null];
        }
        if (! $company->is_active) {
            $row->fail($companyKey, 'inactive_company', "La compañía «{$companyText}» está inactiva.");

            return [null, null];
        }

        $allowed = $this->user ? $this->scope->fullCompanyIds($this->user) : null;
        if ($allowed !== null && ! in_array($company->id, $allowed, true)) {
            $row->fail($companyKey, 'company_out_of_scope', "No tienes acceso completo a la compañía «{$companyText}».");

            return [null, null];
        }

        $branch = $this->branchesOf($company)[Seller::normalize($branchText)] ?? null;
        if (! $branch) {
            $row->fail($branchKey, 'unknown_branch', "La sucursal «{$branchText}» no existe en la compañía «{$company->name}» (se busca por código o nombre).");

            return [$company, null];
        }
        if (! $branch->is_active) {
            $row->fail($branchKey, 'inactive_branch', "La sucursal «{$branchText}» está inactiva.");

            return [$company, null];
        }

        return [$company, $branch];
    }

    /** Resuelve solo la compañía (archivos que no traen sucursal). */
    public function resolveCompany(ImportRow $row, string $companyKey = 'company'): ?Company
    {
        $companyText = $row->text($companyKey);
        if ($companyText === null) {
            return null;
        }

        $company = $this->companies()[Seller::normalize($companyText)] ?? null;
        if (! $company) {
            $row->fail($companyKey, 'unknown_company', "La compañía «{$companyText}» no existe (se busca por código o nombre).");

            return null;
        }
        if (! $company->is_active) {
            $row->fail($companyKey, 'inactive_company', "La compañía «{$companyText}» está inactiva.");

            return null;
        }

        $allowed = $this->user ? $this->scope->fullCompanyIds($this->user) : null;
        if ($allowed !== null && ! in_array($company->id, $allowed, true)) {
            $row->fail($companyKey, 'company_out_of_scope', "No tienes acceso completo a la compañía «{$companyText}».");

            return null;
        }

        return $company;
    }

    /** @return array<string, Company> código o nombre normalizado => compañía (el código tiene prioridad) */
    public function companies(): array
    {
        if ($this->companies === null) {
            $all = Company::withoutGlobalScopes()->get();
            $this->companies = [];
            foreach ($all as $company) {
                $this->companies[Seller::normalize($company->code)] = $company;
            }
            foreach ($all as $company) {
                $this->companies[Seller::normalize($company->name)] ??= $company;
            }
        }

        return $this->companies;
    }

    /** @return array<string, Branch> */
    private function branchesOf(Company $company): array
    {
        if (! isset($this->branches[$company->id])) {
            $all = Branch::withoutGlobalScopes()->where('company_id', $company->id)->get();
            $map = [];
            foreach ($all as $branch) {
                $map[Seller::normalize($branch->code)] = $branch;
            }
            foreach ($all as $branch) {
                $map[Seller::normalize($branch->name)] ??= $branch;
            }
            $this->branches[$company->id] = $map;
        }

        return $this->branches[$company->id];
    }
}
