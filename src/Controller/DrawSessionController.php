<?php

namespace App\Controller;

use App\Exception\DrawSessionProblem;
use App\Service\DrawSessionPublisher;
use App\Service\DrawSessionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * @Route("/{_locale}/draw-sessions", name="draw_session_")
 */
class DrawSessionController extends AbstractController
{
    private $drawSessions;
    private $csrfTokens;
    private $publisher;

    public function __construct(
        DrawSessionService $drawSessions,
        CsrfTokenManagerInterface $csrfTokens,
        DrawSessionPublisher $publisher
    ) {
        $this->drawSessions = $drawSessions;
        $this->csrfTokens = $csrfTokens;
        $this->publisher = $publisher;
    }

    /**
     * @Route("", name="create", methods={"POST"})
     */
    public function create(Request $request): Response
    {
        try {
            $data = $this->jsonBody($request);
            $csrfToken = is_string($data['_token'] ?? null) ? $data['_token'] : '';

            if (!$this->csrfTokens->isTokenValid(new CsrfToken('draw-session-create', $csrfToken))) {
                return $this->problem(403, 'invalid_csrf', 'The page token is invalid. Refresh and try again.');
            }

            $characters = is_array($data['characters'] ?? null) ? $data['characters'] : [];
            $impDrawOrder = $data['impDrawOrder'] ?? null;

            if ($impDrawOrder !== null && !is_int($impDrawOrder)) {
                throw new DrawSessionProblem(422, 'invalid_imp_draw_order', 'Imp draw order must be a number.');
            }

            $created = $this->drawSessions->create($characters, $impDrawOrder);
            $session = $created['session'];
            $joinUrl = $this->generateUrl(
                'draw_join',
                [
                    '_locale' => $request->getLocale(),
                    'publicId' => $session->getPublicId(),
                ],
                UrlGeneratorInterface::ABSOLUTE_URL
            );

            return new JsonResponse([
                'publicId' => $session->getPublicId(),
                'hostSecret' => $created['hostSecret'],
                'joinUrl' => $joinUrl,
                'expiresAt' => $session->getExpiresAt()->format(\DateTimeInterface::ATOM),
                'topic' => $this->publisher->topic($session),
                'hostState' => $this->drawSessions->serializeHost($session),
            ], 201);
        } catch (DrawSessionProblem $problem) {
            return $this->drawProblem($problem);
        }
    }

    /**
     * @Route("/{publicId}", name="public", methods={"GET"}, requirements={"publicId"="[a-f0-9]{64}"})
     */
    public function publicState(string $publicId): Response
    {
        try {
            return new JsonResponse(
                $this->drawSessions->serializePublic($this->drawSessions->find($publicId))
            );
        } catch (DrawSessionProblem $problem) {
            return $this->drawProblem($problem);
        }
    }

    /**
     * @Route("/{publicId}/claims", name="claim", methods={"POST"}, requirements={"publicId"="[a-f0-9]{64}"})
     */
    public function claim(Request $request, string $publicId): Response
    {
        try {
            $data = $this->jsonBody($request);

            if (!is_int($data['number'] ?? null)) {
                throw new DrawSessionProblem(422, 'invalid_number', 'Choose a valid number.');
            }

            $claimed = $this->drawSessions->claim($publicId, $data['number']);

            return new JsonResponse([
                'claimSecret' => $claimed['claimSecret'],
                'claim' => $this->drawSessions->serializeClaim(
                    $claimed['session'],
                    $claimed['slot']
                ),
            ], 201);
        } catch (DrawSessionProblem $problem) {
            return $this->drawProblem($problem);
        }
    }

    /**
     * @Route("/{publicId}/claim", name="claim_state", methods={"GET"}, requirements={"publicId"="[a-f0-9]{64}"})
     */
    public function claimState(Request $request, string $publicId): Response
    {
        try {
            return new JsonResponse(
                $this->drawSessions->getClaim($publicId, $this->bearer($request))
            );
        } catch (DrawSessionProblem $problem) {
            return $this->drawProblem($problem);
        }
    }

    /**
     * @Route("/{publicId}/claim", name="claim_complete", methods={"PATCH"}, requirements={"publicId"="[a-f0-9]{64}"})
     */
    public function completeClaim(Request $request, string $publicId): Response
    {
        try {
            $data = $this->jsonBody($request);

            return new JsonResponse($this->drawSessions->completeClaim(
                $publicId,
                $this->bearer($request),
                $data['name'] ?? null
            ));
        } catch (DrawSessionProblem $problem) {
            return $this->drawProblem($problem);
        }
    }

    /**
     * @Route("/{publicId}/host", name="host", methods={"GET"}, requirements={"publicId"="[a-f0-9]{64}"})
     */
    public function hostState(Request $request, string $publicId): Response
    {
        try {
            return new JsonResponse(
                $this->drawSessions->hostState($publicId, $this->bearer($request))
            );
        } catch (DrawSessionProblem $problem) {
            return $this->drawProblem($problem);
        }
    }

    /**
     * @Route("/{publicId}/host/slots/{number}", name="host_edit_name", methods={"PATCH"}, requirements={"publicId"="[a-f0-9]{64}", "number"="\d+"})
     */
    public function editName(
        Request $request,
        string $publicId,
        int $number
    ): Response {
        try {
            $data = $this->jsonBody($request);

            return new JsonResponse($this->drawSessions->editName(
                $publicId,
                $this->bearer($request),
                $number,
                $data['name'] ?? null
            ));
        } catch (DrawSessionProblem $problem) {
            return $this->drawProblem($problem);
        }
    }

    /**
     * @Route("/{publicId}/host/slots/{number}/release", name="host_release", methods={"POST"}, requirements={"publicId"="[a-f0-9]{64}", "number"="\d+"})
     */
    public function release(
        Request $request,
        string $publicId,
        int $number
    ): Response {
        try {
            return new JsonResponse($this->drawSessions->release(
                $publicId,
                $this->bearer($request),
                $number
            ));
        } catch (DrawSessionProblem $problem) {
            return $this->drawProblem($problem);
        }
    }

    /**
     * @Route("/{publicId}/host/end", name="host_end", methods={"POST"}, requirements={"publicId"="[a-f0-9]{64}"})
     */
    public function end(Request $request, string $publicId): Response
    {
        try {
            return new JsonResponse(
                $this->drawSessions->end($publicId, $this->bearer($request))
            );
        } catch (DrawSessionProblem $problem) {
            return $this->drawProblem($problem);
        }
    }

    private function jsonBody(Request $request): array
    {
        try {
            $data = json_decode($request->getContent(), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new DrawSessionProblem(422, 'invalid_json', 'Send a valid JSON request.');
        }

        if (!is_array($data)) {
            throw new DrawSessionProblem(422, 'invalid_json', 'Send a JSON object.');
        }

        return $data;
    }

    private function bearer(Request $request): string
    {
        $authorization = $request->headers->get('Authorization', '');

        if (!preg_match('/^Bearer ([a-f0-9]{64})$/', $authorization, $matches)) {
            throw new DrawSessionProblem(401, 'missing_credential', 'A capability credential is required.');
        }

        return $matches[1];
    }

    private function drawProblem(DrawSessionProblem $problem): JsonResponse
    {
        return $this->problem(
            $problem->getStatusCode(),
            $problem->getError(),
            $problem->getMessage()
        );
    }

    private function problem(int $status, string $error, string $message): JsonResponse
    {
        return new JsonResponse([
            'error' => $error,
            'message' => $message,
        ], $status);
    }
}
