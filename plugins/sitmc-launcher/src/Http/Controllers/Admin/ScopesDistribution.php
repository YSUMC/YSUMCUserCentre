<?php

namespace SITMinecraft\Launcher\Http\Controllers\Admin;

use Illuminate\Http\Request;

/** The route owns the distribution; form fields and query strings cannot change it. */
trait ScopesDistribution
{
    private function distributionBrand(Request $request): string
    {
        return $request->route('distributionBrand') === 'mutmc' ? 'mutmc' : 'ysumc';
    }

    private function adminRouteName(Request $request, string $suffix): string
    {
        if ($this->distributionBrand($request) === 'mutmc') {
            return 'sitmc-launcher.admin.mutmc'.($suffix === 'index' ? '' : '.'.$suffix);
        }

        return 'sitmc-launcher.admin.'.$suffix;
    }

    private function distributionViewData(Request $request): array
    {
        $brand = $this->distributionBrand($request);

        return [
            'distributionBrand' => $brand,
            'brandLabel' => $brand === 'mutmc' ? 'MUTMCL' : 'YSUMC',
            'adminRoutePrefix' => 'sitmc-launcher.admin.'.($brand === 'mutmc' ? 'mutmc.' : ''),
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
