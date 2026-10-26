<?php

namespace App\Actions\Assets;

use App\Enums\AssetSource;
use App\Http\Requests\StoreAssetRequest;
use App\Models\Asset;
use App\Models\Client;
use App\Models\User;
use App\Rules\SafeUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Phase MCP — ingest base64-encoded media (an image the user pasted into their AI assistant) as a
 * client Asset, reusing the EXACT web-upload safety and storage pipeline. MCP tool calls carry only
 * JSON arguments (no binary channel), so the bytes arrive base64-encoded; this Action turns them back
 * into a validated {@see UploadedFile} and runs it through the same rules `StoreAssetRequest` applies
 * (`mimes:` allowlist, per-type size ceiling and {@see SafeUpload}: content sniffing, SVG/script
 * refusal and the `VirusScanner`) before handing it to {@see CreateAssetAction}. So an assistant can
 * never store anything the web uploader would reject, and the normal probe/thumbnail job still runs.
 *
 * The raw bytes are never logged. See .junie/plans/mcp-server.md.
 */
class CreateAssetFromBase64Action
{
    public function __construct(private readonly CreateAssetAction $createAsset) {}

    /**
     * @param  array{data: string, filename?: string|null, campaign_id?: int|null}  $input
     *
     * @throws ValidationException when the decoded file isn't an allowed,
     *                             content-verified, virus-free upload.
     */
    public function __invoke(Client $client, User $uploader, array $input): Asset
    {
        $bytes = self::decode($input['data']);

        $path = (string) tempnam(sys_get_temp_dir(), 'mcp_media_');
        file_put_contents($path, $bytes);

        try {
            $file = $this->toUploadedFile($path, $input['filename'] ?? null);

            // Identical to StoreAssetRequest::rules() for source=upload — a single safety surface.
            $extensions = array_values(array_map(
                fn (mixed $ext): string => (string) $ext,
                (array) config('assets.allowed_upload_extensions'),
            ));
            Validator::make(['file' => $file], [
                'file' => [
                    'required',
                    'file',
                    'mimes:'.implode(',', $extensions),
                    $this->maxFileSizeRule(),
                    new SafeUpload($extensions),
                ],
            ])->validate();

            $asset = ($this->createAsset)($client, $uploader, [
                'source' => AssetSource::Upload,
                'file' => $file,
                'campaign_id' => $input['campaign_id'] ?? null,
            ]);

            Log::info('MCP media ingested as asset', [
                'org_id' => $client->org_id,
                'client_id' => $client->id,
                'asset_id' => $asset->id,
                'mime_type' => $asset->mime_type,
                'size' => $asset->size,
            ]);

            return $asset;
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * Decode a base64 payload, tolerating a `data:<mime>;base64,` data-URL prefix.
     *
     * @throws ValidationException on undecodable input
     */
    private static function decode(string $data): string
    {
        $data = preg_replace('#^data:[^;,]*;base64,#i', '', trim($data)) ?? $data;

        $bytes = base64_decode(strtr($data, ' ', '+'), true);

        if ($bytes === false || $bytes === '') {
            throw ValidationException::withMessages([
                'data' => __('The media could not be decoded — send the file as base64-encoded bytes.'),
            ]);
        }

        return $bytes;
    }

    /**
     * Wrap the stored bytes in a test-mode UploadedFile. The original name's extension is what
     * {@see SafeUpload} checks the sniffed content against, so when no filename is supplied we derive
     * one from the content itself rather than trusting a caller-provided mime hint.
     */
    private function toUploadedFile(string $path, ?string $filename): UploadedFile
    {
        if ($filename === null || $filename === '' || pathinfo($filename, PATHINFO_EXTENSION) === '') {
            $guessed = (new UploadedFile($path, 'upload', null, null, true))->guessExtension();
            $filename = 'upload.'.($guessed !== null && $guessed !== '' ? $guessed : 'bin');
        }

        return new UploadedFile($path, basename($filename), null, null, true);
    }

    /**
     * Video files get the higher ceiling; everything else the image/document one — same as
     * {@see StoreAssetRequest::maxFileSizeRule()}.
     */
    private function maxFileSizeRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! $value instanceof UploadedFile) {
                return;
            }

            $isVideo = str_starts_with((string) $value->getMimeType(), 'video/');
            $maxKb = (int) ($isVideo
                ? config('assets.max_video_upload_size_kb')
                : config('assets.max_upload_size_kb'));

            if ($value->getSize() > $maxKb * 1024) {
                $fail('The media may not be greater than '.$maxKb.' kilobytes.');
            }
        };
    }
}
