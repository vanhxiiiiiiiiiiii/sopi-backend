<?php

namespace App\Services;

use Error;
use Illuminate\Support\Facades\DB;

class QueryService
{
    /**
     * Update multiple records of the table.
     *
     * @param string $table
     * @param array $values
     * @param string $primaryKey
     * @return int
     */
    public function updateMultipleRecords($table, $values, $primaryKey = 'id', callable $callback = null)
    {
        if (count($values) === 0) {
            return 0;
        }

        $newValues = array_map(function ($value) use ($callback) {
            if (!is_null($callback)) {
                $value = $callback($value);
            }

            return $value;
        }, $values);

        $updatedColumns = array_values(array_filter(
            array_keys($newValues[0]),
            fn ($column) => $column !== $primaryKey,
        ));

        return DB::transaction(function () use ($table, $newValues, $primaryKey, $updatedColumns) {
            return DB::table($table)->upsert($newValues, [$primaryKey], $updatedColumns);
        });
    }
}
