<?php

namespace Messerli90\FirstPromoter;

use Exception;

class Promoter extends FirstPromoter
{
    /**
     * Creates a promoter and, when a code or ref id is given, sets them on the
     * default campaign. The returned object keeps the promotion fields the
     * apps already read.
     *
     * @param       $email
     * @param array $options
     *
     * @return object
     */
    public static function create($email, array $options = [])
    {
        $body = [
            'email' => $email,
        ];
        if (!empty($options['cust_id'])) {
            $body['cust_id'] = (string) $options['cust_id'];
        }

        $profile = [];
        if (!empty($options['first_name'])) {
            $profile['first_name'] = $options['first_name'];
        }
        if (!empty($options['last_name'])) {
            $profile['last_name'] = $options['last_name'];
        }
        if ($profile) {
            $body['profile'] = $profile;
        }

        $created = self::request('POST', 'promoters', $body);
        if (empty($created->id)) {
            throw new Exception('FirstPromoter did not return a promoter id.');
        }

        $campaignId = $created->promoter_campaigns[0]->id ?? null;
        if (!empty($options['promo_code']) || !empty($options['ref_id'])) {
            if (!$campaignId) {
                throw new Exception('FirstPromoter created the promoter without a campaign.');
            }
            $campaign = [];
            if (!empty($options['promo_code'])) {
                $campaign['coupon'] = $options['promo_code'];
            }
            if (!empty($options['ref_id'])) {
                $campaign['ref_token'] = $options['ref_id'];
            }
            $updatedCampaign = self::request('PUT', 'promoter_campaigns/' . $campaignId, $campaign);
            $created = self::request('GET', 'promoters/' . $created->id);
            if (isset($created->promoter_campaigns[0]) && isset($updatedCampaign->rewards_for_referrals)) {
                $created->promoter_campaigns[0]->rewards_for_referrals = $updatedCampaign->rewards_for_referrals;
            }
        }

        return self::legacyPromoter($created);
    }

    /**
     * @param array $options
     *
     * @return object
     * @throws Exception
     */
    public static function show(array $options)
    {
        return self::legacyPromoter(self::find($options));
    }

    /**
     * Updates the campaign coupon and ref token. A status of suspended blocks
     * the promoter on that campaign.
     *
     * @param array $options
     *
     * @return object
     * @throws Exception
     */
    public static function update(array $options)
    {
        if (($options['status'] ?? null) === 'suspended') {
            return self::block($options);
        }

        $promoter = self::find($options);
        $campaignId = $promoter->promoter_campaigns[0]->id ?? null;
        $campaign = [];
        if (!empty($options['promo_code'])) {
            $campaign['coupon'] = $options['promo_code'];
        }
        if (!empty($options['new_ref_id'])) {
            $campaign['ref_token'] = $options['new_ref_id'];
        }
        if ($campaign) {
            if (!$campaignId) {
                throw new Exception('FirstPromoter promoter has no campaign to update.');
            }
            $updatedCampaign = self::request('PUT', 'promoter_campaigns/' . $campaignId, $campaign);
            $promoter = self::request('GET', 'promoters/' . $promoter->id);
            if (isset($promoter->promoter_campaigns[0]) && isset($updatedCampaign->rewards_for_referrals)) {
                $promoter->promoter_campaigns[0]->rewards_for_referrals = $updatedCampaign->rewards_for_referrals;
            }
        }

        return self::legacyPromoter($promoter);
    }

    /**
     * Archives the promoter. v2 has no hard delete.
     *
     * @param array $options
     *
     * @return object
     * @throws Exception
     */
    public static function delete(array $options)
    {
        $promoter = self::find($options);
        $result = self::request('POST', 'promoters/archive', [
            'ids' => [(int) $promoter->id],
        ]);
        self::assertBatch($result, 'archive');

        return $result;
    }

    /**
     * Short-lived dashboard token. Generate one for each page view.
     *
     * @param mixed $custId
     * @param mixed $promoterId
     *
     * @return object
     * @throws Exception
     */
    public static function iframeLogin($custId = null, $promoterId = null)
    {
        if (($custId === null || $custId === '') && ($promoterId === null || $promoterId === '')) {
            throw new Exception('Promoter id or customer id is required.');
        }

        $query = $promoterId !== null && $promoterId !== ''
            ? ['promoter_id' => $promoterId]
            : ['cust_id' => (string) $custId];

        return self::request(
            'POST',
            'https://v2.firstpromoter.com/api/v2/promoters/iframe_login',
            $query,
            true
        );
    }

    /**
     * Commissions for the promoter who owns this ref token.
     *
     * @param string $refId
     *
     * @return array
     * @throws Exception
     */
    public static function commissionsForRefId($refId)
    {
        $promoter = self::find(['ref_id' => $refId]);
        $rows = [];
        $seen = [];

        // The company list defaults to 25 rows. PayRicky keeps last month from
        // this result, so a short page drops that month for a busy promoter.
        for ($page = 1; $page <= 100; $page++) {
            if ($page > 1) {
                usleep(300000);
            }
            $response = null;
            for ($attempt = 1; $attempt <= 6; $attempt++) {
                try {
                    $response = self::request('GET', 'commissions', [
                        'filters' => [
                            'promoter_id' => $promoter->id,
                        ],
                        'per_page' => 100,
                        'page' => $page,
                    ]);
                    break;
                } catch (Exception $e) {
                    if ((int) $e->getCode() !== 429 || $attempt === 6) {
                        throw $e;
                    }
                    sleep(5 * $attempt);
                }
            }

            $batch = [];
            if (is_array($response)) {
                $batch = $response;
            } elseif (isset($response->data) && is_array($response->data)) {
                $batch = $response->data;
            }
            if (!$batch) {
                break;
            }

            $added = 0;
            foreach ($batch as $row) {
                $id = $row->id ?? null;
                if ($id !== null) {
                    if (isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                }
                if (!isset($row->conversion_amount)) {
                    $row->conversion_amount = $row->sale_amount ?? ($row->amount ?? 0);
                }
                if (!isset($row->promoter) && isset($row->promoter_campaign->promoter)) {
                    $row->promoter = $row->promoter_campaign->promoter;
                }
                $rows[] = $row;
                $added++;
            }

            if ($added === 0 || count($batch) < 100) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param array $options
     *
     * @return object
     * @throws Exception
     */
    protected static function find(array $options)
    {
        $attempts = [];
        if (!empty($options['id'])) {
            $attempts[] = [(string) $options['id'], null];
        }

        $lookup = [
            'cust_id' => 'cust_id',
            'ref_id' => 'ref_token',
            'email' => 'email',
            'promo_code' => 'promo_code',
            'auth_token' => 'auth_token',
        ];
        foreach ($lookup as $key => $findBy) {
            if (!empty($options[$key])) {
                $attempts[] = [(string) $options[$key], $findBy];
            }
        }

        if (!$attempts) {
            throw new Exception('ID, customer id, referral id, or auth token are required.');
        }

        $last = null;
        foreach ($attempts as $attempt) {
            try {
                $query = $attempt[1] ? ['find_by' => $attempt[1]] : [];

                return self::request('GET', 'promoters/' . rawurlencode($attempt[0]), $query);
            } catch (Exception $e) {
                $last = $e;
                if (!in_array((int) $e->getCode(), [404, 422], true)) {
                    throw $e;
                }
            }
        }

        throw $last;
    }

    /**
     * @param array $options
     *
     * @return object
     * @throws Exception
     */
    protected static function block(array $options)
    {
        $promoter = self::find($options);
        $campaignId = $promoter->promoter_campaigns[0]->campaign_id ?? null;
        if (!$campaignId) {
            throw new Exception('FirstPromoter promoter has no campaign to block.');
        }

        $result = self::request('POST', 'promoters/block', [
            'campaign_id' => (int) $campaignId,
            'ids' => [(int) $promoter->id],
        ]);
        self::assertBatch($result, 'block');

        return $result;
    }

    /**
     * @param object $promoter
     *
     * @return object
     */
    protected static function legacyPromoter($promoter)
    {
        $campaign = $promoter->promoter_campaigns[0] ?? null;
        $promotion = new \stdClass();
        $promotion->id = $campaign->id ?? null;
        $promotion->promo_code = $campaign->coupon ?? ($campaign->display_coupon ?? null);
        $promotion->ref_id = $campaign->ref_token ?? null;
        $rewardName = self::rewardName($campaign);
        if (is_string($rewardName) && $rewardName !== '') {
            $offer = new \stdClass();
            $offer->name = $rewardName;
            $promotion->current_offer = $offer;
        }
        $promoter->promotions = [$promotion];
        if (!isset($promoter->auth_token)) {
            $promoter->auth_token = null;
        }

        return $promoter;
    }

    /**
     * The offer name Don stores is the promoter reward. Campaign defaults are
     * used when the promoter campaign has not customized its rewards.
     *
     * @param object|null $campaign
     *
     * @return string|null
     */
    protected static function rewardName($campaign)
    {
        if (!$campaign) {
            return null;
        }

        foreach (['rewards_for_promoters', 'rewards_for_referrals'] as $key) {
            $name = $campaign->$key[0]->reward->name ?? null;
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        $campaignId = $campaign->campaign_id ?? ($campaign->campaign->id ?? null);
        if (!$campaignId) {
            return null;
        }

        static $cached = [];
        if (!array_key_exists($campaignId, $cached)) {
            try {
                $remote = self::request('GET', 'campaigns/' . rawurlencode((string) $campaignId));
                $cached[$campaignId] = $remote->rewards_for_promoters[0]->reward->name
                    ?? ($remote->rewards_for_referrals[0]->reward->name ?? null);
            } catch (Exception $e) {
                $cached[$campaignId] = null;
            }
        }

        $name = $cached[$campaignId];

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * @param object $result
     * @param string $action
     *
     * @return void
     * @throws Exception
     */
    protected static function assertBatch($result, $action)
    {
        $status = $result->status ?? null;
        if ($status === 'failed' || $status === 'stopped') {
            throw new Exception('FirstPromoter ' . $action . ' ' . $status . '.');
        }
    }
}
