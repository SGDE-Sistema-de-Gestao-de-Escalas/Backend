<?php

namespace App\Repositories\Interfaces;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;

interface BaseRepositoryInterface
{
    public function all(array $columns = ['*']): Collection;
    public function find(int $id, array $columns = ['*']): ?Model;
    public function create(array $data): Model;
    public function update(Model $model, array $data): bool;
    public function delete(Model $model): ?bool;
}