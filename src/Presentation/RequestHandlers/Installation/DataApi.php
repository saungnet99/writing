<?php

declare(strict_types=1);

namespace Presentation\RequestHandlers\Installation;

use Billing\Domain\Repositories\PlanRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Easy\Container\Attributes\Inject;
use Easy\Http\Message\RequestMethod;
use Easy\Router\Attributes\Route;
use Presentation\Exceptions\UnprocessableEntityException;
use Presentation\Response\EmptyResponse;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;
use User\Domain\Repositories\UserRepositoryInterface;

#[Route(path: '/database/data', method: RequestMethod::POST)]
class DataApi extends InstallationApi implements
    RequestHandlerInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private PlanRepositoryInterface $planRepo,
        private UserRepositoryInterface $userRepo,

        #[Inject('config.dirs.root')]
        private string $rootDir,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $this->migrateData($request);
        } catch (Throwable $th) {
            $path = $this->rootDir . '/.env';

            if (file_exists($path)) {
                unlink($path);
            }

            throw new UnprocessableEntityException($th->getMessage());
        }

        return new EmptyResponse();
    }


    private function migrateData(ServerRequestInterface $request): void
    {
        $em = $this->em;
        $sm = $this->em->getConnection()->createSchemaManager();
        $payload = $request->getParsedBody();
        $ratios = $payload->ratios;

        if ($sm->tablesExist(['category_bkp'])) {
            // Migrate category_bkp to category
            $em->getConnection()->executeStatement(
                "INSERT INTO category (id, title, created_at, updated_at) 
                SELECT id, title, created_at, updated_at FROM category_bkp"
            );
        }

        if ($sm->tablesExist(['option_bkp'])) {
            // Migrate option_bkp to category
            $em->getConnection()->executeStatement(
                "INSERT INTO `option` (id, `key`, `value`, created_at, updated_at) 
                SELECT id, `key`, `value`, created_at, updated_at FROM option_bkp"
            );
        }

        if ($sm->tablesExist(['preset_bkp'])) {
            // Migrate preset_bkp to category
            $em->getConnection()->executeStatement(
                "INSERT INTO preset (id, `type`, `status`, created_at, updated_at, title, description, template, category_id, image, color, is_locked) 
                SELECT  id, `type`, `status`, created_at, updated_at, title, description, template, category_id, image, color, is_locked FROM preset_bkp"
            );
        }

        if ($sm->tablesExist(['plan_bkp'])) {
            // Fetchh all plans
            $plans = $em->getConnection()->fetchAllAssociative(
                "SELECT * FROM plan_bkp"
            );

            foreach ($plans as $p) {
                $credit_count = 0;

                if ($p['token_credit_count'] === null) {
                    $credit_count = null;
                } else {
                    $credit_count = $p['token_credit_count'] * $ratios->token;
                }

                if ($p['image_credit_count'] === null || $credit_count === null) {
                    $credit_count = null;
                } else {
                    $credit_count += $p['image_credit_count'] * $ratios->image;
                }

                if ($p['audio_credit_count'] === null || $credit_count === null) {
                    $credit_count = null;
                } else {
                    $credit_count += $p['audio_credit_count'] * $ratios->audio;
                }

                $em->getConnection()->executeStatement(
                    "INSERT INTO plan (id, created_at, updated_at, billing_cycle, status, title,  description, price, superiority, is_featured, icon, feature_list, credit_count) 
                    VALUE (:id, :created_at, :updated_at, :billing_cycle, :status, :title, :description, :price, :superiority, :is_featured, :icon, :feature_list, :credit_count)",
                    [
                        'id' => $p['id'],
                        'created_at' => $p['created_at'],
                        'updated_at' => $p['updated_at'],
                        'billing_cycle' => $p['billing_cycle'],
                        'status' => $p['status'],
                        'title' => $p['title'],
                        'description' => $p['description'],
                        'price' => $p['price'],
                        'superiority' => $p['superiority'],
                        'is_featured' => $p['is_featured'],
                        'icon' => $p['icon'],
                        'feature_list' => $p['feature_list'],
                        'credit_count' => $credit_count
                    ]
                );
            }
        }

        /** @var PlanEntity */
        foreach ($this->planRepo as $plan) {
            $plan->getSnapshot();
        }
        $em->flush();

        $plan_snapshot_map = $em->getConnection()->fetchAllKeyValue(
            "SELECT id, snapshot_id FROM plan"
        );

        $user_subs_map = [];
        if ($sm->tablesExist(['user_bkp'])) {
            // Migrate user_bkp to user
            $em->getConnection()->executeStatement(
                "INSERT INTO user (id, created_at, updated_at, email, password_hash, first_name, last_name, language, role, status, recovery_token, is_email_verified, email_verification_token) 
                SELECT 
                    id, created_at, updated_at, email, password_hash, first_name, last_name, language, role, status, recovery_token, is_email_verified, email_verification_token
                FROM user_bkp"
            );

            $user_subs_map = $em->getConnection()->fetchAllKeyValue(
                "SELECT id, active_subscription_id FROM user_bkp"
            );
        }

        /** @var UserEntity */
        foreach ($this->userRepo as $user) {
            // Create default workspace for all users
            $user->postLoad();
        }

        $em->flush();

        $user_ws_map = $em->getConnection()->fetchAllKeyValue(
            "SELECT id, current_workspace_id FROM user"
        );

        // Migrate documents
        if ($sm->tablesExist(['document_bkp'])) {
            // Fetchh all plans
            $docs = $em->getConnection()->fetchAllAssociative(
                "SELECT * FROM document_bkp"
            );

            foreach ($docs as $p) {
                $em->getConnection()->executeStatement(
                    "INSERT INTO library_item (id, workspace_id, user_id, visibility, created_at, updated_at, request_params, model, discr, used_credit_count,  preset_id, title, content, output_file_id, input_file_id, voice_id) 
                    VALUE (
                        :id, 
                        :workspace_id, 
                        :user_id, 
                        :visibility, 
                        :created_at, 
                        :updated_at, 
                        :request_params, 
                        :model, 
                        :discr, 
                        :used_credit_count, 
                        :preset_id, 
                        :title, 
                        :content, 
                        :output_file_id, 
                        :input_file_id, 
                        :voice_id
                    )",
                    [
                        'id' => $p['id'],
                        'workspace_id' => $user_ws_map[$p['user_id']],
                        'user_id' => $p['user_id'],
                        'visibility' => 0,
                        'created_at' => $p['created_at'],
                        'updated_at' => $p['updated_at'],
                        'request_params' => "{}",
                        'model' => "gpt-3.5",
                        'discr' => 'document',
                        'used_credit_count' => 0,
                        'preset_id' => $p['preset_id'],
                        'title' => $p['title'],
                        'content' => $p['output'],
                        'output_file_id' => NULL,
                        'input_file_id' => NULL,
                        'voice_id' => NULL
                    ]
                );
            }
        }

        // Migrate user subscriptions (active subscriptions only)
        if ($sm->tablesExist(['subscription_bkp'])) {
            $subs = $em->getConnection()->fetchAllAssociative(
                "SELECT 
                subscription_bkp.*, 
                plan_bkp.billing_cycle, 
                plan_bkp.token_credit_count,
                plan_bkp.image_credit_count,
                plan_bkp.audio_credit_count
                FROM subscription_bkp LEFT JOIN plan_bkp ON subscription_bkp.plan_id = plan_bkp.id"
            );

            $user_credits = [];
            foreach ($subs as $sub) {
                if ($sub['billing_cycle'] == 'one-time') {
                    $left = $user_credits[$sub['user_id']] ?? 0;

                    if (is_null($sub['token_credit_count'])) {
                        $left = NULL;
                    } else {
                        $left = ($sub['token_credit_count'] - $sub['token_usage_count']) * $ratios->token;
                    }

                    if (is_null($left) || is_null($sub['image_credit_count'])) {
                        $left = NULL;
                    } else {
                        $left += ($sub['image_credit_count'] - $sub['image_usage_count']) * $ratios->image;
                    }

                    if (is_null($left) || is_null($sub['audio_credit_count'])) {
                        $left = NULL;
                    } else {
                        $left += ($sub['audio_credit_count'] - $sub['audio_usage_count']) * $ratios->audio;
                    }

                    $user_credits[$sub['user_id']] = $left;
                    continue;
                }

                $usage_count = 0;

                if ($sub['token_usage_count'] === null) {
                    $usage_count = null;
                } else {
                    $usage_count = $sub['token_usage_count'] * $ratios->token;
                }

                if ($sub['image_usage_count'] === null || $usage_count === null) {
                    $usage_count = null;
                } else {
                    $usage_count += $sub['image_usage_count'] * $ratios->image;
                }

                if ($sub['audio_usage_count'] === null || $usage_count === null) {
                    $usage_count = null;
                } else {
                    $usage_count += $sub['audio_usage_count'] * $ratios->audio;
                }

                $renew_date = $sub['reset_credits_at'];
                $end_date = $sub['expire_at'];

                if ($end_date || !$sub['status']) {
                    $renew_date = null;

                    if (!$end_date) {
                        $end_date = date('Y-m-d H:i:s');
                    }
                }

                $em->getConnection()->executeStatement(
                    "INSERT INTO subscription (id, workspace_id, plan_id, created_at, updated_at, trial_period_days, payment_gateway, external_id, order_id, canceled_at, cancel_at, ended_at, renew_at, usage_count) 
                    VALUE (
                        :id, 
                        :workspace_id, 
                        :plan_id, 
                        :created_at, 
                        :updated_at, 
                        :trial_period_days, 
                        :payment_gateway, 
                        :external_id, 
                        :order_id, 
                        :canceled_at, 
                        :cancel_at, 
                        :ended_at, 
                        :renew_at, 
                        :usage_count
                    )",
                    [
                        'id' => $sub['id'],
                        'workspace_id' => $user_ws_map[$sub['user_id']],
                        'plan_id' => $plan_snapshot_map[$sub['plan_id']],
                        'created_at' => $sub['created_at'],
                        'updated_at' => $sub['updated_at'],
                        'trial_period_days' => $sub['trial_period_days'],
                        'payment_gateway' => $sub['payment_gateway'],
                        'external_id' => $sub['external_id'],
                        'order_id' => NULL,
                        'canceled_at' => $end_date,
                        'cancel_at' => $end_date,
                        'ended_at' => $end_date,
                        'renew_at' => $renew_date,
                        'usage_count' => $usage_count
                    ]
                );
            }

            foreach ($user_subs_map as $user_id => $sub_id) {
                $em->getConnection()->executeStatement(
                    "UPDATE workspace SET subscription_id = :sub_id WHERE id = :id",
                    ['sub_id' => $sub_id, 'id' => $user_ws_map[$user_id]]
                );
            }

            foreach ($user_credits as $user_id => $count) {
                $em->getConnection()->executeStatement(
                    "UPDATE workspace SET credit_count = :amount WHERE id = :id",
                    ['amount' => $count, 'id' => $user_ws_map[$user_id]]
                );
            }
        }
    }
}
