<?php

/*
 * This file is part of the CCDNForum ForumBundle
 *
 * (c) CCDN (c) CodeConsortium <http://www.codeconsortium.com/>
 *
 * Available on github <http://www.github.com/codeconsortium/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CCDNForum\ForumBundle\Controller;

use Symfony\Component\DependencyInjection\ContainerAware;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use CCDNForum\ForumBundle\Entity\Topic;
use CCDNForum\ForumBundle\Entity\Post;
use CCDNForum\ForumBundle\Entity\Draft;

/**
 *
 * @author Reece Fowell <reece@codeconsortium.com>
 * @version 1.0
 */
class TopicController extends TopicBaseController
{
    /**
     *
     * @access public
     * @param int $topicId, int $page
     * @return RedirectResponse|RenderResponse
     */
    public function showAction($topicId, $page)
    {
		// Get topic.
		$topic = $this->getTopicManager()->findOneByIdWithBoardAndCategory($topicId);
		$this->isFound($topic);

		// Get posts for topic paginated.
		$postsPager = $this->getPostManager()->findAllPaginatedByTopicId($topicId, $page);
		$this->isFound($postsPager->getCurrentPageResults());

        // get the topic subscriptions.
		$subscription = $this->getSubscriptionManager()->findSubscriptionForTopicById($topicId);		
        $subscriberCount = $this->getSubscriptionManager()->countSubscriptionsForTopicById($topicId);

		// Incremenet view counter.
        $this->getTopicManager()->incrementViewCounter($topic);

        // setup crumb trail.
        $board = $topic->getBoard();
        $category = $board->getCategory();

        $crumbs = $this->getCrumbs()
            ->add($this->trans('ccdn_forum_forum.crumbs.forum_index'), $this->path('ccdn_forum_forum_category_index'), "home")
            ->add($category->getName(), $this->path('ccdn_forum_forum_category_show', array('categoryId' => $category->getId())), "category")
            ->add($board->getName(), $this->path('ccdn_forum_forum_board_show', array('boardId' => $board->getId())), "board")
            ->add($topic->getTitle(), $this->path('ccdn_forum_forum_topic_show', array('topicId' => $topic->getId())), "communication");

        return $this->renderResponse('CCDNForumForumBundle:Topic:show.html.', array(
            'crumbs' => $crumbs,
            'pager' => $postsPager,
            'board' => $board,
            'topic' => $topic,
            //'registries' => $registries,
            'subscription' => $subscription,
            'subscription_count' => $subscriberCount,
        ));
    }

    /**
     *
     * @access public
     * @param int $boardId, int $draftId
     * @return RedirectResponse|RenderResponse
     */
    public function createAction($boardId, $draftId)
    {
        $this->isAuthorised('ROLE_USER');

		$board = $this->getBoardManager()->findOneByIdWithCategory($boardId);
        $this->isFound($board);
		$this->isAuthorisedToCreateTopic($board);

		$formHandler = $this->getFormHandlerToCreateTopic($board, $draftId);

		// Flood Control.
		if (! $this->getFloodControl()->isFlooded()) {
            if ($formHandler->process($this->getRequest())) {
                $this->getFloodControl()->incrementCounter();

				$this->setFlash('success', $this->trans('ccdn_forum_forum.flash.topic.create.success', array('%topic_title%' => $formHandler->getForm()->getData()->getTopic()->getTitle())));

                return new RedirectResponse($this->path('ccdn_forum_forum_topic_show', array('topicId' => $formHandler->getForm()->getData()->getTopic()->getId() )));
            }
		} else {
			$this->setFlash('warning', $this->trans('ccdn_forum_forum.flash.topic.flood_control'));
		}

        // setup crumb trail.
        $category = $board->getCategory();

        $crumbs = $this->getCrumbs()
            ->add($this->trans('ccdn_forum_forum.crumbs.forum_index'), $this->path('ccdn_forum_forum_category_index'), "home")
            ->add($category->getName(), $this->path('ccdn_forum_forum_category_show', array('categoryId' => $category->getId())), "category")
            ->add($board->getName(), $this->path('ccdn_forum_forum_board_show', array('boardId' => $board->getId())), "board")
            ->add($this->trans('ccdn_forum_forum.crumbs.topic.create'), $this->path('ccdn_forum_forum_topic_create', array('boardId' => $board->getId())), "edit");

        return $this->renderResponse('CCDNForumForumBundle:Topic:create.html.', array(
            'crumbs' => $crumbs,
            'board' => $board,
            'preview' => $formHandler->getForm()->getData(),
            'form' => $formHandler->getForm()->createView(),
        ));
    }

    /**
     *
     * @access public
     * @param int $topicId, int $quoteId, int $draftId
     * @return RedirectResponse|RenderResponse
     */
    public function replyAction($topicId, $quoteId, $draftId)
    {
        $this->isAuthorised('ROLE_USER');

		$topic = $this->getTopicManager()->findOneByIdWithPostsByTopicId($topicId);
        $this->isFound($topic);
		$this->isAuthorisedToReplyToTopic($topic);

		$formHandler = $this->getFormHandlerToReplyToTopic($topic, $draftId, $quoteId);

		// Flood Control.
		if ( ! $this->getFloodControl()->isFlooded()) {
            if ($formHandler->process($this->getRequest())) {
				$this->getFloodControl()->incrementCounter();
				
                // Page of the last post.
				$page = $this->getTopicManager()->getPageForPostOnTopic($topic, $topic->getLastPost());
				
                $this->setFlash('success', $this->trans('ccdn_forum_forum.flash.topic.reply.success', array('%topic_title%' => $topic->getTitle())));

                return new RedirectResponse($this->path('ccdn_forum_forum_topic_show_paginated_anchored', array('topicId' => $topicId, 'page' => $page, 'postId' => $topic->getLastPost()->getId()) ));
            }
		} else {
			$this->setFlash('warning', $this->trans('ccdn_forum_forum.flash.topic.flood_control'));
		}
		
        // setup crumb trail.
        $board = $topic->getBoard();
        $category = $board->getCategory();

        $crumbs = $this->getCrumbs()
            ->add($this->trans('ccdn_forum_forum.crumbs.forum_index'), $this->path('ccdn_forum_forum_category_index'), "home")
            ->add($category->getName(), $this->path('ccdn_forum_forum_category_show', array('categoryId' => $category->getId())), "category")
            ->add($board->getName(), $this->path('ccdn_forum_forum_board_show', array('boardId' => $board->getId())), "board")
            ->add($topic->getTitle(), $this->path('ccdn_forum_forum_topic_show', array('topicId' => $topic->getId())), "communication")
            ->add($this->trans('ccdn_forum_forum.crumbs.topic.reply'), $this->path('ccdn_forum_forum_topic_reply', array('topicId' => $topic->getId())), "edit");

        return $this->renderResponse('CCDNForumForumBundle:Topic:reply.html.', array(
            'crumbs' => $crumbs,
            'topic' => $topic,
            //'preview' => $formHandler->getForm()->getData(),
            'form' => $formHandler->getForm()->createView(),
        ));
    }
}
