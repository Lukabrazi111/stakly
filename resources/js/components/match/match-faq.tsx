import {
    Accordion,
    AccordionContent,
    AccordionItem,
    AccordionTrigger,
} from '@/components/ui/accordion';
import { useT } from '@/lib/i18n';

interface FaqItem {
    question: string;
    answer: string;
}

const FAQ_ITEMS: FaqItem[] = [
    {
        question: 'Where do I actually play the game?',
        answer: "On chess.com or Lichess — whichever platform the listing was created for. Stakly doesn't host the chessboard; we handle the escrow and the result verification. Open the platform you've linked, play your opponent, then come back here. We do the rest automatically.",
    },
    {
        question: 'How does Stakly know who won?',
        answer: 'We read the result directly from the chess.com or Lichess API as soon as your game finishes. The match settles automatically — no "I won / I lost" buttons. The API is the source of truth, so neither player can claim a result the game didn\'t produce.',
    },
    {
        question: 'How long does it take to settle after the game ends?',
        answer: "Usually within a few seconds. We poll continuously while you're on this page and every five minutes in the background as a safety net, so even if you close the tab the match will settle on its own. chess.com archives can lag 5–15 seconds; Lichess is real-time.",
    },
    {
        question: 'What if something goes wrong during the match?',
        answer: "Two escape hatches: Request cancellation (your opponent has to agree — both stakes refunded, no fee, doesn't count toward your record) or Report a problem (sends the match to admin review). Use cancellation for cooperative exits, Report a problem for cheating, ghosting, or anything else where you need a human to look.",
    },
    {
        question: 'How is the platform fee calculated?',
        answer: "A flat 10% of the pot, taken from the winner's payout. For example, on a $100 stake (pot = $200), the winner takes $180 and Stakly takes $20. Draws have no fee — both players get their original stake back in full.",
    },
    {
        question: 'When do I receive my payout?',
        answer: 'Immediately when the match settles. Your USDT balance updates the moment the result is recorded — refresh your wallet to see it.',
    },
];

export function MatchFaq() {
    const t = useT();

    return (
        <section className="rounded-2xl border border-border/60 bg-card/60">
            <header className="border-b border-border/60 px-6 py-4">
                <h2 className="text-sm font-semibold text-foreground">
                    {t('FAQ')}
                </h2>
            </header>
            <Accordion type="single" collapsible className="px-6">
                {FAQ_ITEMS.map((item, index) => (
                    <AccordionItem key={item.question} value={`item-${index}`}>
                        <AccordionTrigger>{t(item.question)}</AccordionTrigger>
                        <AccordionContent>{t(item.answer)}</AccordionContent>
                    </AccordionItem>
                ))}
            </Accordion>
        </section>
    );
}
