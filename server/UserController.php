<?php

use Ajthinking\LaravelPostgis\Geometries\LineString;
use Ajthinking\LaravelPostgis\Geometries\Point;
use Ajthinking\LaravelPostgis\Geometries\Polygon;

class UserController extends Controller
{
    public function updateArea(User $user, Request $request)
    {
        $points = collect($request->coordinates[0])->map(function (array $point) {
            return new Point($point[1], $point[0]);
        })->toArray();

        $lineString = new LineString($points);

        $user->update([
            'area' => new Polygon([$lineString]),
        ]);

        $user->updateRadiuses();

        $request->session()->flash('status', 'A área de entrega da loja foi atualizada.');

        return back();
    }
}
