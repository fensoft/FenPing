<?php

declare(strict_types=1);

namespace FenPing\Api\Controller;

use FenPing\Api\AuthPolicy;
use FenPing\Api\HttpException;
use FenPing\Api\Request;
use FenPing\Api\Route;
use FenPing\Dhcp\HostValidator;
use FenPing\Ipam\IpamService;
use FenPing\Realtime\LiveUpdateScope;
use InvalidArgumentException;

final readonly class IpamController implements Controller
{
    public function __construct(private IpamService $ipam, private HostValidator $validator)
    {
    }

    public function routes(): array
    {
        return [
            new Route('GET', '/ipam', fn(Request $request, array $params): array => $this->ipam->summary()),
            new Route('GET', '/ipam/conflicts', fn(Request $request, array $params): array => $this->ipam->conflicts()),
            new Route(
                'PUT',
                '/ipam/devices/{mac}/approval',
                fn(Request $request, array $params): array => $this->ipam->approve($this->mac($params['mac'])),
                AuthPolicy::Session,
                [LiveUpdateScope::Hosts],
            ),
            new Route(
                'DELETE',
                '/ipam/devices/{mac}/approval',
                fn(Request $request, array $params): array => $this->ipam->unapprove($this->mac($params['mac'])),
                AuthPolicy::Session,
                [LiveUpdateScope::Hosts],
            ),
        ];
    }

    private function mac(mixed $value): string
    {
        try {
            return $this->validator->mac($value);
        } catch (InvalidArgumentException $error) {
            throw new HttpException(400, $error->getMessage());
        }
    }
}
