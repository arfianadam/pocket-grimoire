<?php

namespace App\Controller;

use App\Exception\DrawSessionProblem;
use App\Service\DrawSessionPublisher;
use App\Service\DrawSessionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DrawPageController extends AbstractController
{
    /**
     * @Route("/{_locale}/draw/{publicId}", name="draw_join", methods={"GET"}, requirements={"publicId"="[a-f0-9]{64}"})
     */
    public function join(
        string $publicId,
        DrawSessionService $drawSessions,
        DrawSessionPublisher $publisher,
        string $mercurePublicUrl
    ): Response {
        try {
            $session = $drawSessions->find($publicId);
        } catch (DrawSessionProblem $problem) {
            return $this->render('pages/draw.html.twig', [
                'publicId' => $publicId,
                'initialState' => null,
                'loadError' => $problem->getMessage(),
                'topic' => null,
                'mercurePublicUrl' => $mercurePublicUrl,
            ], $problem->getStatusCode());
        }

        return $this->render('pages/draw.html.twig', [
            'publicId' => $publicId,
            'initialState' => $drawSessions->serializePublic($session),
            'loadError' => null,
            'topic' => $publisher->topic($session),
            'mercurePublicUrl' => $mercurePublicUrl,
        ]);
    }
}
