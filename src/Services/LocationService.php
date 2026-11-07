<?php
declare(strict_types=1);

namespace MajiLink\Services;

use MajiLink\Http\HttpException;
use MajiLink\Support\Db;
use MajiLink\Support\Validator;

final class LocationService
{
    public function list(int $userId): array
    {
        $rows = Db::all(
            'SELECT id, alias, lat, lng, landmark_note, is_default, created_at
             FROM locations WHERE user_id = ? ORDER BY is_default DESC, id ASC',
            [$userId]
        );
        return array_map([$this, 'present'], $rows);
    }

    public function find(int $userId, int $id): array
    {
        $row = Db::one('SELECT * FROM locations WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw HttpException::notFound('Delivery pin not found.');
        }
        return $this->present($row);
    }

    /**
     * MySQL hands TINYINT(1) back as a number, but the clients type these fields as
     * booleans and refuse to parse 0/1. Normalise on the way out.
     */
    private function present(array $row): array
    {
        $row['is_default'] = (bool) $row['is_default'];
        return $row;
    }

    public function create(int $userId, array $data): array
    {
        $input = $this->validate($data);
        $isDefault = !empty($input['is_default']) || Db::value('SELECT COUNT(*) FROM locations WHERE user_id = ?', [$userId]) == 0;

        $id = Db::transaction(function () use ($userId, $input, $isDefault): int {
            if ($isDefault) {
                Db::run('UPDATE locations SET is_default = 0 WHERE user_id = ?', [$userId]);
            }
            return Db::insert('locations', [
                'user_id'       => $userId,
                'alias'         => $input['alias'],
                'lat'           => $input['lat'],
                'lng'           => $input['lng'],
                'landmark_note' => $input['landmark_note'] ?? null,
                'is_default'    => $isDefault ? 1 : 0,
            ]);
        });

        return $this->find($userId, $id);
    }

    public function update(int $userId, int $id, array $data): array
    {
        $this->find($userId, $id);
        $input = $this->validate($data, true);
        $update = array_intersect_key($input, array_flip(['alias', 'lat', 'lng', 'landmark_note']));
        if ($update !== []) {
            Db::update('locations', $update, 'id = ? AND user_id = ?', [$id, $userId]);
        }
        if (!empty($input['is_default'])) {
            Db::run('UPDATE locations SET is_default = 0 WHERE user_id = ?', [$userId]);
            Db::update('locations', ['is_default' => 1], 'id = ?', [$id]);
        }
        return $this->find($userId, $id);
    }

    public function delete(int $userId, int $id): void
    {
        $location = $this->find($userId, $id);
        Db::run('DELETE FROM locations WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ((int) $location['is_default'] === 1) {
            $next = Db::one('SELECT id FROM locations WHERE user_id = ? ORDER BY id ASC LIMIT 1', [$userId]);
            if ($next !== null) {
                Db::update('locations', ['is_default' => 1], 'id = ?', [$next['id']]);
            }
        }
    }

    private function validate(array $data, bool $partial = false): array
    {
        $required = $partial ? 'nullable' : 'required';
        return Validator::validate($data, [
            'alias'         => $required . '|string|min:2|max:80',
            'lat'           => $required . '|lat',
            'lng'           => $required . '|lng',
            'landmark_note' => 'nullable|string|max:255',
            'is_default'    => 'nullable|bool',
        ]);
    }
}
