import {
    Accordion,
    AccordionContent,
    AccordionItem,
    AccordionTrigger,
} from '@/components/ui/accordion';

interface FaqItem {
    question: string;
    answer: string;
}

/**
 * Stakly-specific match FAQ — covers the six questions players ask most
 * during a live match (where to play, what happens on disagreement, timeout
 * behaviour, fee math, no-cancel rule, payout timing). Content is hard-coded
 * because it doesn't vary by match state and re-deriving from server props
 * per render would be pointless. If we ever need editorial updates without
 * a deploy, we can lift to config or DB-backed copy.
 */
const FAQ_ITEMS: FaqItem[] = [
    {
        question: 'What if my opponent doesn’t confirm the outcome?',
        answer:
            'Every match has a 4-hour confirmation window. If only one of you has confirmed when the timer runs out, that claim is honoured — Won settles in your favour, Lost settles in your opponent’s. If neither of you confirmed, the game API arbitrates by reading the result directly off chess.com or Lichess.',
    },
    {
        question: 'What happens if we disagree on the outcome?',
        answer:
            'Either player can open a dispute from this page. Stakly then checks the official chess.com or Lichess API for the game result — the API is authoritative and overrides both self-reports. If the API can’t determine a winner, the match is flagged for admin review and your stakes stay in escrow until it’s resolved.',
    },
    {
        question: 'Where do I actually play the game?',
        answer:
            'On chess.com or Lichess. Stakly doesn’t host the chessboard — we handle the escrow and outcome verification. Open whichever platform you’ve linked, play your opponent, then come back here to confirm who won.',
    },
    {
        question: 'How is the platform fee calculated?',
        answer:
            'A flat 10% of the pot, taken from the winner’s payout. For example, on a $100 stake (pot = $200), the winner takes $180 and Stakly takes $20. Draws have no fee — both players get their original stake back in full.',
    },
    {
        question: 'Can I cancel the match after it’s started?',
        answer:
            'No. Once you’ve taken a listing, the only ways out are: both players confirming the outcome, either of you opening a dispute, or the 4-hour confirmation window expiring. This protects both stakes from being clawed back mid-game.',
    },
    {
        question: 'When do I receive my payout?',
        answer:
            'Immediately when the match settles. Your USDT balance updates the moment the result is recorded — refresh your wallet to see it.',
    },
];

export function MatchFaq() {
    return (
        <section className="border-border/60 bg-card/60 rounded-2xl border">
            <header className="border-border/60 border-b px-6 py-4">
                <h2 className="text-foreground text-sm font-semibold">FAQ</h2>
            </header>
            <Accordion type="single" collapsible className="px-6">
                {FAQ_ITEMS.map((item, index) => (
                    <AccordionItem
                        key={item.question}
                        value={`item-${index}`}
                    >
                        <AccordionTrigger>{item.question}</AccordionTrigger>
                        <AccordionContent>{item.answer}</AccordionContent>
                    </AccordionItem>
                ))}
            </Accordion>
        </section>
    );
}
