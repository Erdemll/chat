import { expect, test } from 'vitest';
import { useAuthFormFeedback } from './auth-form-feedback';

test('csrf and rate limit failures produce actionable messages instead of a silent submission', () => {
    const feedback = useAuthFormFeedback();
    expect(feedback.onHttpException({ status: 419 })).toBe(false);
    expect(feedback.requestError.value).toContain('Sayfayı yenileyip');
    expect(feedback.requestError.value).toContain('çerez');
    expect(feedback.onHttpException({ status: 429 })).toBe(false);
    expect(feedback.requestError.value).toContain('Bir dakika bekleyip');
});

test('network and server errors stay visible without exposing server response details', () => {
    const feedback = useAuthFormFeedback();
    expect(feedback.onNetworkError()).toBe(false);
    expect(feedback.requestError.value).toContain('İnternet bağlantınızı');
    const response = { status: 500, data: 'secret provider details' };
    expect(feedback.onHttpException(response)).toBe(false);
    expect(feedback.requestError.value).toBe(
        'İşlem tamamlanamadı. Lütfen biraz sonra tekrar deneyin.',
    );
});

test('new attempts clear previous feedback and different forms do not share state', () => {
    const first = useAuthFormFeedback();
    const second = useAuthFormFeedback();
    first.onNetworkError();
    expect(second.requestError.value).toBe('');

    first.clearRequestError();

    expect(first.requestError.value).toBe('');
});
