<?php

declare(strict_types=1);

namespace App\Controllers\API\V1\Restaurant;

use App\Common\Exceptions\ApiException;
use App\Common\Validation\WapiRules;
use App\Controllers\API\BaseApiController;
use App\Modules\Restaurants\Services\RestaurantService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Inscription et gestion du restaurant : POST /api/v1/restaurants et /api/v1/restaurant/*
 */
final class RestaurantController extends BaseApiController
{
    public function create(): ResponseInterface
    {
        $profile = (new RestaurantService())->create($this->auth()->userId(), $this->input());

        return $this->created($profile, 'Restaurant enregistré. Il sera visible après validation par WapiFood.');
    }

    public function profile(): ResponseInterface
    {
        return $this->ok((new RestaurantService())->profile($this->restaurantId()));
    }

    public function update(): ResponseInterface
    {
        return $this->ok((new RestaurantService())->update($this->restaurantId(), $this->auth()->userId(), $this->input()), 'Profil mis à jour.');
    }

    public function availability(): ResponseInterface
    {
        $input = $this->input();

        if (! array_key_exists('is_paused', $input) || ! (new WapiRules())->wf_boolean($input['is_paused'])) {
            throw ApiException::validation(['is_paused' => 'Le champ is_paused doit être un booléen (true ou false).']);
        }

        $paused = WapiRules::toBool($input['is_paused']);

        return $this->ok(
            (new RestaurantService())->setPaused($this->restaurantId(), $this->auth()->userId(), $paused),
            $paused ? 'Restaurant fermé temporairement.' : 'Restaurant rouvert.',
        );
    }

    public function submit(): ResponseInterface
    {
        return $this->ok((new RestaurantService())->resubmit($this->restaurantId(), $this->auth()->userId()), 'Restaurant soumis à nouveau pour validation.');
    }

    public function uploadLogo(): ResponseInterface
    {
        return $this->ok((new RestaurantService())->replaceImage($this->restaurantId(), $this->auth()->userId(), 'logo', $this->request->getFile('file')), 'Logo mis à jour.');
    }

    public function uploadCover(): ResponseInterface
    {
        return $this->ok((new RestaurantService())->replaceImage($this->restaurantId(), $this->auth()->userId(), 'cover', $this->request->getFile('file')), 'Image de couverture mise à jour.');
    }
}
