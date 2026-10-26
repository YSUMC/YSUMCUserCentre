<?php

namespace SITMinecraft\Launcher\Http\Controllers\Admin;

use Illuminate\Http\Request;

/** The route owns the distribution; form fields and query strings cannot change it. */
trait ScopesDistribution
{
    private function distributionBrand(Request $request): string
    {
        return 'ysumc';
    }

    private function adminRouteName(Request $request, string $suffix): string
    {
        return 'sitmc-launcher.admin.'.$suffix;
    }

    private function distributionViewData(Request $request): array
    {
        $brand = $this->distributionBrand($request);

        return [
            'distributionBrand' => $brand,
            'brandLabel' => 'YSUMC',
            'adminRoutePrefix' => 'sitmc-launcher.admin.',
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
