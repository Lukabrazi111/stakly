import { useEffect, useRef, useState } from 'react';
import ReactCrop, { centerCrop, makeAspectCrop } from 'react-image-crop';
import type { Crop, PixelCrop } from 'react-image-crop';
import 'react-image-crop/dist/ReactCrop.css';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

interface Props {
    file: File | null;
    open: boolean;
    onClose: () => void;
    onConfirm: (blob: Blob) => void;
}

// Minimum dimensions of the on-screen crop selection. Below this the cropped
// 512×512 output starts looking pixelated when we upscale the source region.
const MIN_CROP_PX = 100;

// Output canvas dimensions. Matches the server-side `main` Spatie conversion
// (User::registerMediaConversions), so the server never has to upscale — only
// re-encode + normalise.
const OUTPUT_SIZE = 512;

export function AvatarCropModal({ file, open, onClose, onConfirm }: Props) {
    const [imageSrc, setImageSrc] = useState<string | null>(null);
    const [crop, setCrop] = useState<Crop | undefined>(undefined);
    const [completedCrop, setCompletedCrop] = useState<PixelCrop | undefined>(
        undefined,
    );
    const [isProcessing, setIsProcessing] = useState(false);
    const imgRef = useRef<HTMLImageElement | null>(null);

    // Read the picked file into a data URL so `<img>` can render it. Reset
    // state when the file changes (re-pick) or clears (modal close).
    useEffect(() => {
        if (!file) {
            setImageSrc(null);
            setCrop(undefined);
            setCompletedCrop(undefined);

            return;
        }

        const reader = new FileReader();
        reader.onload = () => setImageSrc(reader.result as string);
        reader.readAsDataURL(file);
    }, [file]);

    const handleImageLoad = (e: React.SyntheticEvent<HTMLImageElement>) => {
        const { naturalWidth, naturalHeight } = e.currentTarget;
        const initial = centerCrop(
            makeAspectCrop(
                { unit: '%', width: 80 },
                1,
                naturalWidth,
                naturalHeight,
            ),
            naturalWidth,
            naturalHeight,
        );
        setCrop(initial);
    };

    const handleConfirm = async () => {
        if (!imgRef.current || !completedCrop) {
            return;
        }

        setIsProcessing(true);

        try {
            const blob = await cropToBlob(
                imgRef.current,
                completedCrop,
                OUTPUT_SIZE,
            );
            onConfirm(blob);
            onClose();
        } finally {
            setIsProcessing(false);
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                if (!value) {
                    onClose();
                }
            }}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Crop your avatar</DialogTitle>
                    <DialogDescription>
                        Drag the corners to adjust. Your avatar is always
                        square.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex max-h-[60vh] justify-center overflow-hidden rounded-lg bg-background/60">
                    {imageSrc && (
                        <ReactCrop
                            crop={crop}
                            onChange={(_, percentCrop) => setCrop(percentCrop)}
                            onComplete={(pixelCrop) =>
                                setCompletedCrop(pixelCrop)
                            }
                            aspect={1}
                            minWidth={MIN_CROP_PX}
                            minHeight={MIN_CROP_PX}
                            circularCrop
                            keepSelection
                            className="max-h-[60vh]"
                        >
                            <img
                                ref={imgRef}
                                src={imageSrc}
                                alt="Crop preview"
                                onLoad={handleImageLoad}
                                className="max-h-[60vh] object-contain"
                            />
                        </ReactCrop>
                    )}
                </div>

                <DialogFooter>
                    <Button
                        variant="ghost"
                        type="button"
                        onClick={onClose}
                        disabled={isProcessing}
                    >
                        Cancel
                    </Button>
                    <Button
                        variant="gradient"
                        type="button"
                        onClick={handleConfirm}
                        disabled={!completedCrop || isProcessing}
                    >
                        {isProcessing ? 'Saving…' : 'Use this crop'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Render the cropped region of `image` onto a square `outputSize × outputSize`
 * canvas and return a JPEG blob at quality 0.92. The result lives inside the
 * server's 2 MB validation cap by a wide margin (~50–100 KB) which keeps the
 * upload network-light too.
 */
async function cropToBlob(
    image: HTMLImageElement,
    crop: PixelCrop,
    outputSize: number,
): Promise<Blob> {
    const scaleX = image.naturalWidth / image.width;
    const scaleY = image.naturalHeight / image.height;
    const canvas = document.createElement('canvas');
    canvas.width = outputSize;
    canvas.height = outputSize;
    const ctx = canvas.getContext('2d');

    if (!ctx) {
        throw new Error('Canvas 2D context unavailable');
    }

    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(
        image,
        crop.x * scaleX,
        crop.y * scaleY,
        crop.width * scaleX,
        crop.height * scaleY,
        0,
        0,
        outputSize,
        outputSize,
    );

    return new Promise<Blob>((resolve, reject) => {
        canvas.toBlob(
            (blob) => {
                if (blob) {
                    resolve(blob);
                } else {
                    reject(new Error('Canvas toBlob returned null'));
                }
            },
            'image/jpeg',
            0.92,
        );
    });
}
