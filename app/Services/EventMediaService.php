<?php

namespace App\Services;

use App\Models\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class EventMediaService
{
    public function image(?string $input, int $eventId, string $field, array &$created): ?string
    {
        if ($input === null || $input === '') {
            return null;
        }
        if (str_starts_with($input, 'data:')) {
            if (! preg_match('#^data:image/(jpeg|png|gif|webp);base64,([a-zA-Z0-9+/=\r\n]+)$#D', $input, $matches)) {
                $this->invalid($field);
            }
            $bytes = base64_decode($matches[2], true);
            $info = $bytes === false ? false : @getimagesizefromstring($bytes);
            if (! $info || strlen($bytes) > 5 * 1024 * 1024 || $info[0] * $info[1] > 40000000) {
                $this->invalid($field);
            }
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
            if (! isset($extensions[$info['mime']]) || $info['mime'] !== 'image/'.$matches[1]) {
                $this->invalid($field);
            }
            $path = 'events/images/'.$eventId.'/'.hash('sha256', $bytes).'.'.$extensions[$info['mime']];
            if (! Storage::disk('public')->exists($path)) {
                $created[] = $path;
                if (! Storage::disk('public')->put($path, $bytes)) {
                    throw new \RuntimeException('Image storage failed.');
                }
            }
            $url = Storage::disk('public')->url($path);
            if (strlen($url) > 2000) {
                $this->invalid($field);
            }

            return $url;
        }
        if (strlen($input) > 2000 || ! filter_var($input, FILTER_VALIDATE_URL) || ! in_array(parse_url($input, PHP_URL_SCHEME), ['http', 'https'], true)) {
            // The local filesystem disk may return a relative public storage URL.
            if (! $this->managedPath($input)) {
                $this->invalid($field);
            }
        }
        $path = $this->managedPath($input);
        if ($path !== null && preg_match('#^events/images/(\d+)/#', $path, $owner)) {
            if ((int) $owner[1] !== $eventId || ! Storage::disk('public')->exists($path)) {
                $this->invalid($field);
            }
        }

        return $input;
    }

    public function isUpload(string $url): bool
    {
        return $this->managedPath($url) !== null;
    }

    public function discardUnreferenced(int $eventId, array $paths): void
    {
        DB::transaction(function () use ($eventId, $paths) {
            $event = Event::whereKey($eventId)->lockForUpdate()->first();
            foreach (array_unique($paths) as $candidate) {
                $path = str_starts_with($candidate, 'events/images/') ? $candidate : $this->managedPath($candidate);
                if (! $path || ! str_starts_with($path, 'events/images/'.$eventId.'/')) {
                    continue;
                }
                $url = Storage::disk('public')->url($path);
                $used = Event::where('main_image', $url)->orWhere('banner_url', $url)->orWhere('banner_image', $url)->exists()
                    || DB::table('event_media')->where('url', $url)->exists();
                $profileReferences = DB::table('event_public_profiles')->where('organiser_image', $url);
                foreach (['moments', 'guests', 'crew'] as $column) {
                    $cast = DB::getDriverName() === 'pgsql' ? "CAST($column AS TEXT)" : "CAST($column AS CHAR)";
                    foreach ([$url, str_replace('/', '\\/', $url)] as $needle) {
                        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle).'%';
                        $profileReferences->orWhereRaw("$cast LIKE ? ESCAPE '!'", [$pattern]);
                    }
                }
                if ($profileReferences->exists()) {
                    $used = true;
                }
                if ($event && $event->publicProfile && str_contains(json_encode($event->publicProfile->toArray(), JSON_UNESCAPED_SLASHES), $url)) {
                    $used = true;
                }
                if (! $used) {
                    Storage::disk('public')->delete($path);
                }
            }
        });
    }

    public function discardCreated(array $paths): void
    {
        $groups = [];
        foreach ($paths as $path) {
            if (preg_match('#^events/images/(\d+)/#', $path, $match)) {
                $groups[(int) $match[1]][] = $path;
            }
        }
        foreach ($groups as $id => $owned) {
            $this->discardUnreferenced($id, $owned);
        }
    }

    private function managedPath(string $url): ?string
    {
        $base = rtrim(Storage::disk('public')->url(''), '/').'/';
        if (! str_starts_with($url, $base)) {
            return null;
        }
        $path = substr($url, strlen($base));

        return preg_match('#^events/images/(?:[0-9]+/)?[a-zA-Z0-9-]+\.(jpg|jpeg|png|gif|webp)$#D', $path) ? $path : null;
    }

    private function invalid(string $field): never
    {
        throw ValidationException::withMessages([$field => ['Use a valid JPEG, PNG, GIF or WebP image up to 5 MB, or an HTTP(S) image URL.']]);
    }
}
