<?php

declare(strict_types=1);

namespace Staatic\WordPress\Module\Rest;

use Staatic\WordPress\Module\ModuleInterface;
use Staatic\WordPress\Publication\PublicationRepository;
use Staatic\WordPress\Publication\PublicationTaskProvider;
use Staatic\WordPress\Service\Formatter;
use WP_Error;
use WP_REST_Request;

final class PublicationStatusEndpoint implements ModuleInterface
{
    /**
     * @var PublicationRepository
     */
    private $publicationRepository;

    /**
     * @var PublicationTaskProvider
     */
    private $publicationTaskProvider;

    /**
     * @var Formatter
     */
    private $formatter;

    public const NAMESPACE = 'staatic/v1';

    public const ENDPOINT = '/publication-status';

    public function __construct(PublicationRepository $publicationRepository, PublicationTaskProvider $publicationTaskProvider, Formatter $formatter)
    {
        $this->publicationRepository = $publicationRepository;
        $this->publicationTaskProvider = $publicationTaskProvider;
        $this->formatter = $formatter;
    }

    public function hooks() : void
    {
        \add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes() : void
    {
        \register_rest_route(self::NAMESPACE, self::ENDPOINT, [[
            'methods' => 'POST',
            'callback' => [$this, 'render'],
            'permission_callback' => [$this, 'permissionCallback'],
            'args' => []
        ]]);
    }

    public function render(WP_REST_Request $request)
    {
        $params = \json_decode($request->get_body(), \true);
        $publicationId = $params['id'] ?? null;
        if (!$publicationId) {
            return new WP_Error('staatic', \__('Invalid request', 'staatic'), [
                'status' => 400
            ]);
        }
        $publication = $this->publicationRepository->find($publicationId);
        if (!$publication) {
            \wp_send_json_error();
        }
        $build = $publication->build();
        $deployment = $publication->deployment();
        $currentTask = $publication->currentTask() ? $this->publicationTaskProvider->getTask(
            $publication->currentTask()
        ) : null;

        return \rest_ensure_response([
            'publication' => [
                'id' => $publication->id(),
                'isPreview' => $publication->isPreview(),
                'status' => $publication->status()->status(),
                'currentTask' => $currentTask ? [
                    'name' => $currentTask->name(),
                    'description' => $currentTask->description()
                ] : null,
                'publisher' => $publication->publisher() ? $publication->publisher()->data->display_name : null
            ],
            'progress' => [
                'numUrlsCrawlable' => $this->formatter->number($build->numUrlsCrawlable()),
                'numUrlsCrawled' => $this->formatter->number($build->numUrlsCrawled()),
                'crawlPercent' => $build->numUrlsCrawlable() ? \round(
                    $build->numUrlsCrawled() / $build->numUrlsCrawlable() * 100,
                    2
                ) : 0,
                'numResultsDeployable' => $this->formatter->number($deployment->numResultsDeployable()),
                'numResultsDeployed' => $this->formatter->number($deployment->numResultsDeployed()),
                'deployPercent' => $deployment->numResultsDeployable() ? \round(
                    $deployment->numResultsDeployed() / $deployment->numResultsDeployable() * 100,
                    2
                ) : 0,
                'dateDeploymentFinished' => $this->formatter->shortDate($deployment->dateFinished()),
                'timeTaken' => $publication->status()->isFinished() ? $this->formatter->difference(
                    $build->dateCrawlStarted(),
                    $deployment->dateFinished()
                ) : null
            ]
        ]);
    }

    public function permissionCallback(WP_REST_Request $request)
    {
        return \current_user_can('edit_posts');
    }
}
