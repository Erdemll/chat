import { ref } from 'vue';

export function useAuthFormFeedback() {
    const requestError = ref('');

    return {
        requestError,
        clearRequestError() {
            requestError.value = '';
        },
        onHttpException(response: { status: number }) {
            requestError.value =
                response.status === 419
                    ? 'Sayfanın oturumu sona erdi veya çerez doğrulanamadı. Sayfayı yenileyip tekrar deneyin; tarayıcınızda bu site için çerezlere izin verildiğini kontrol edin.'
                    : response.status === 429
                      ? 'Çok fazla deneme yaptınız. Bir dakika bekleyip tekrar deneyin.'
                      : 'İşlem tamamlanamadı. Lütfen biraz sonra tekrar deneyin.';
            return false;
        },
        onNetworkError() {
            requestError.value =
                'Sunucuya ulaşılamadı veya sayfa yüklenemedi. İnternet bağlantınızı kontrol edip sayfayı yenileyin.';
            return false;
        },
    };
}
