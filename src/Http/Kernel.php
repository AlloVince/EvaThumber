<?php

declare(strict_types=1);

namespace EvaThumber\Http;

use EvaThumber\Cache\DiskCache;
use EvaThumber\Exception\ImageException;
use EvaThumber\Image\IsolatedProcessor;
use EvaThumber\Source\LocalSource;
use EvaThumber\Url\Parser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final readonly class Kernel implements HttpKernelInterface
{
    public function __construct(private Settings $settings)
    {
    }

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        try {
            if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
                return new JsonResponse(['error' => 'method_not_allowed'], 405, ['Allow' => 'GET, HEAD']);
            }
            if ($request->getPathInfo() === '/healthz') {
                return new JsonResponse(['status' => 'ok', 'version' => '2.0.0-dev'], 200, ['Cache-Control' => 'no-store']);
            }
            if (strlen($request->getRequestUri()) > $this->settings->limits->maxUrlLength) {
                throw new ImageException('URL exceeds length limit.', 414, 'url_too_long');
            }
            foreach ($request->query->all() as $name => $value) {
                if (!in_array($name, ['_a', '_i'], true) || !is_string($value)) {
                    throw new ImageException('Unsupported query parameter.');
                }
            }
            $url = (new Parser($this->settings->limits))->parse($request->getPathInfo());
            $source = (new LocalSource($this->settings->source, $this->settings->limits))->resolve($url->publicId);
            $requestedFormat = $url->transformation->get('f');
            $auto = $requestedFormat === 'auto';
            $format = $auto ? (new FormatNegotiator())->negotiate(($request->headers->get('Accept') ?? '*/*')) : ($requestedFormat ?? $url->format ?? $source->format);
            $identity = json_encode(['evathumber-2-policy-3', \EvaThumber\Image\AutoQuality::POLICY, $source->identity, $url->version, $url->transformation->canonical(), $format, get_object_vars($this->settings->limits)], JSON_THROW_ON_ERROR);
            $processor = new IsolatedProcessor($this->settings);
            $entry = (new DiskCache($this->settings->cache, $this->settings->cacheBytes, $this->settings->cacheEntries))->remember(
                $identity, $format,
                fn (string $destination) => $processor->write($url->publicId, $url->transformation, $destination, $format),
            );
            $mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'avif' => 'image/avif', 'gif' => 'image/gif'][$format];
            $response = new CachedFileResponse($entry, ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff', 'X-EvaThumber-Cache' => $entry->hit ? 'HIT' : 'MISS']);
            $response->setPublic();
            $response->setMaxAge($this->settings->maxAge);
            $response->setEtag($entry->etag);
            $response->setLastModified(new \DateTimeImmutable('@' . max($source->modifiedAt, $entry->modifiedAt)));
            if ($auto) {
                $response->setVary('Accept');
            }
            $response->isNotModified($request);
            return $response->prepare($request);
        } catch (ImageException $error) {
            $response = new JsonResponse(['error' => $error->error, 'message' => $error->getMessage()], $error->status, ['Cache-Control' => 'no-store']);
            if ($error->status === 503) {
                $response->headers->set('Retry-After', '1');
            }
            return $response;
        } catch (\Throwable $error) {
            if (!$catch) {
                throw $error;
            }
            error_log($error::class . ': ' . $error->getMessage());
            return new JsonResponse(['error' => 'internal_error'], 500, ['Cache-Control' => 'no-store']);
        }
    }
}
