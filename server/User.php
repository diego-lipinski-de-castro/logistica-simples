<?php

class User
{
    use PostgisTrait;

    public const CHARGE_STYLES = [
        'LINE' => 'Linha reta',
        'ROUTE' => 'Rota',
        'OPEN' => 'Livre (Last mile)',
        'DAILY' => 'Diária (Last mile)',
        'PACKAGE' => 'Pacote (Last mile)',
    ];

    protected $fillable = [
        'area',
        'radiuses',
        'charge_style',
        'charge_options',
    ];

    protected $postgisFields = [
        'area',
    ];

    protected $postgisTypes = [
        'area' => [
            'geomtype' => 'geography',
            'srid' => 3557,
        ],
    ];

    public function setRadiusesAttribute($value): void
    {
        if (is_array($value)) {
            $value = collect($value);
        }

        $this->attributes['radiuses'] = $value;
    }

    public function getRadiusesAttribute($value)
    {
        if (blank($this->area) || blank($value)) {
            return [];
        }

        $collection = collect(json_decode($value, true))
            ->when($this->charge_style == 'LINE', fn ($c) => $c->whereNotNull('rad'))
            ->transform(function ($item) {
                $item['formatted_paid'] = Helper::toMoney($item['paid']);
                $item['formatted_charged'] = Helper::toMoney($item['charged']);
                
                return $item;
            });

        if ($this->charge_style == 'ROUTE' && $collection->last()['rad'] != null) {
            $collection->push([
                'rad' => null,
                'time' => 0,
                'paid' => 0,
                'charged' => 0,
                'formatted_paid' => Helper::toMoney(0),
                'formatted_charged' => Helper::toMoney(0),
            ]);
        }

        return $collection;
    }

    public function updateRadiuses()
    {
        if (blank($this->radiuses)) {
            $this->update([
                'radiuses' => $this->mockRadiuses(),
            ]);

            return;
        }

        $radiuses = $this->radiuses
            ->whereNotNull('rad')
            ->map(function ($item) {
                return [
                    'rad' => $item['rad'],
                    'time' => $item['time'],
                    'paid' => Helper::extractNumbersFromString($item['paid']),
                    'charged' => Helper::extractNumbersFromString($item['charged']),
                ];
            });

        $mock = $this->mockRadiuses()->whereNotNull('rad');

        // if current radiuses count is higher than mock count, it means the new area is smaller than before
        // if current radiuses count is smaller than mock count, it means the new area is bigger than before
        if ($radiuses->count() > $mock->count()) {
            $this->update([
                'radiuses' => $radiuses->take($mock->count()),
            ]);
        } else {
            $this->update([
                'radiuses' => $radiuses->union($mock),
            ]);
        }
    }

    private function mockRadiuses(): \Illuminate\Support\Collection
    {
        $radiuses = $this->getRadiusRange()->map(function ($item) {
            return [
                'rad' => $item,
                'time' => 0,
                'paid' => 0,
                'charged' => 0,
            ];
        });

        return $radiuses;
    }

    private function getRadiusRange(): \Illuminate\Support\Collection
    {
        $range = collect(range(1, $this->getMaxRadius()));

        return $range;
    }

    private function getMaxRadius(): int
    {
        if (blank($this->area)) {
            return 0;
        }

        $result = DB::select(<<<EOT
                WITH location_geom AS (
                    SELECT
                        position::geometry
                    FROM
                        users
                    INNER JOIN
                        addresses
                    ON
                        users.id = addresses.user_id
                    WHERE users.id = $this->id
                ),
                area_geom AS (
                    SELECT
                        area::geometry
                    FROM
                        users
                    WHERE id = $this->id
                ),
                location AS (
                    SELECT
                        (ST_DumpPoints (location_geom.position)).*
                    FROM
                        location_geom
                ),
                area AS (
                    SELECT
                        (ST_DumpPoints (area_geom.area)).*
                    FROM
                        area_geom
                )
                SELECT
                    st_distancesphere (location.geom,
                        area.geom) AS distance
                FROM
                    location,
                    area
                ORDER BY
                    distance DESC limit 1;
        EOT);
    
        $maxRadius = $result[0]->distance;

        $maxRadius = (int) ceil($maxRadius / 1000);

        return $maxRadius;
    }
}