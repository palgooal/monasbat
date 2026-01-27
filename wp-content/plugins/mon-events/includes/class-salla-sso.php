<?php
if (!defined('ABSPATH')) exit;

class Mon_Salla_SSO
{
    private $client_id     = '82febe64-c582-46d5-8dd2-c7938eddf2de';
    private $client_secret = '07ac5a341a0dcf57669205d05544ae61d9c5e4d64a5230d46c0ae85aebf95503';
    private $redirect_uri  = 'https://mon.wpgoals.com/salla-callback-sso';

    public function __construct()
    {
        add_action('init', [$this, 'add_custom_rewrite_rule']);
        add_action('parse_request', [$this, 'handle_salla_response']);
    }

    // إخبار ووردبريس بأن هذا الرابط يخصنا
    public function add_custom_rewrite_rule()
    {
        add_rewrite_rule('^salla-callback-sso/?$', 'index.php?salla_callback=1', 'top');
    }

    public function get_login_url()
    {
        $url = "https://accounts.salla.sa/oauth2/auth?";
        $params = [
            'client_id'     => $this->client_id,
            'redirect_uri'  => $this->redirect_uri,
            'response_type' => 'code',
            'scope'         => 'profile read_customers offline_access', // الصلاحيات الكاملة
            'state'         => wp_create_nonce('salla_sso_state')
        ];
        return $url . http_build_query($params);
    }

    public function handle_salla_response()
    {
        if (strpos($_SERVER['REQUEST_URI'], 'salla-callback-sso') !== false && isset($_GET['code'])) {

            $access_token = $this->exchange_code_for_token($_GET['code']);

            if ($access_token) {
                $user_profile = $this->get_salla_user_profile($access_token);
                if ($user_profile) {
                    $this->login_or_create_user($user_profile);
                }
            } else {
                wp_die('خطأ: فشل استلام التوكن من سلة. يرجى التأكد من أن التطبيق "مثبت" في متجرك التجريبي.');
            }
        }
    }

    private function exchange_code_for_token($code)
    {
        $response = wp_remote_post('https://accounts.salla.sa/oauth2/token', [
            'body' => [
                'grant_type'    => 'authorization_code',
                'client_id'     => $this->client_id,
                'client_secret' => $this->client_secret,
                'redirect_uri'  => $this->redirect_uri,
                'code'          => $code,
            ],
        ]);

        if (is_wp_error($response)) return false;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['access_token'] ?? false;
    }

    private function get_salla_user_profile($access_token)
    {
        $response = wp_remote_get('https://accounts.salla.sa/oauth2/user/info', [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Accept'        => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) return false;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['data'] ?? false;
    }

    private function login_or_create_user($salla_user)
    {
        $email = $salla_user['email'];
        $user  = get_user_by('email', $email);

        if (!$user) {
            $user_id = wp_create_user($email, wp_generate_password(), $email);
            if (is_wp_error($user_id)) wp_die($user_id->get_error_message());
            $user = get_user_by('id', $user_id);
        }

        wp_update_user([
            'ID'           => $user->ID,
            'first_name'   => $salla_user['first_name'] ?? '',
            'last_name'    => $salla_user['last_name'] ?? '',
            'display_name' => $salla_user['name'] ?? $email,
        ]);

        if (!empty($salla_user['mobile'])) {
            update_user_meta($user->ID, 'billing_phone', $salla_user['mobile']);
        }

        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID);

        // التوجيه للوحة التحكم
        wp_redirect(home_url('/dashboard'));
        exit;
    }
}
new Mon_Salla_SSO();

/**
 * إضافة زر الدخول بواسطة سلة في صفحة الدخول
 */
add_action('login_form', function () {
    $sso = new Mon_Salla_SSO();
    $login_url = $sso->get_login_url();
?>
    <div style="margin-bottom: 20px; text-align: center;">
        <a href="<?php echo esc_url($login_url); ?>"
            style="display: block; background-color: #56d0b6; color: #fff; padding: 12px; border-radius: 6px; text-decoration: none; font-weight: bold; border: 1px solid #45b19a;">
            <img src="https://salla.sa/favicon.ico" style="width:16px; vertical-align:middle; margin-left:8px;">
            الدخول السريع بواسطة سلة
        </a>
        <p style="margin-top:10px; font-size:11px; color:#777;">سيتم إنشاء حساب لك تلقائياً إذا كانت هذه زيارتك الأولى</p>
        <hr style="margin: 20px 0; border: 0; border-top: 1px solid #eee;">
    </div>
<?php
});
