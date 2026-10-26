<?php

namespace MUTMC\Launcher\Http\Controllers\Admin;

use Illuminate\Http\Request;

/** The route owns the distribution; form fields and query strings cannot change it. */
trait ScopesDistribution
{
    private function distributionBrand(Request $request): string
    {
        return 'mutmc';
    }

    private function adminRouteName(Request $request, string $suffix): string
    {
        return 'mutmc-launcher.admin.'.$suffix;
    }

    private function distributionViewData(Request $request): array
    {
        $brand = $this->distributionBrand($request);

        return [
            'distributionBrand' => $brand,
            'brandLabel' => 'MUTMCL',
            'adminRoutePrefix' => 'mutmc-launcher.admin.',
            'adminIndexRoute' => $this->adminRouteName($request, 'index'),
        ];
    }

    private function requireDistribution(Request $request, ?array $row): array
    {
        if ($row === null || $row['distribution_brand'] !== $this->distributionBrand($request)) {
            abort(404);
        }

        return $row;
    }
}
