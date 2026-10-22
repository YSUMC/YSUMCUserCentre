import { Inject, Injectable } from '@nestjs/common';
import * as oidc from 'oidc-provider';
import { BS_RESOURCE_INDICATOR, OIDCProviderService } from './oidc-provider.service';
import { Request, Response } from "express";
import { EXTENDED_PRISMA_SERVICE, PrismaStore } from './extended-prisma-client';
import { getDateWithTimezoneOffset } from './helper';
import { CodeIdToUUID, PassportAuthCode, User } from '@prisma/client';
import { YggCScopes } from './blessing.types';

@Injectable()
export class AppService {

    readonly callback: (req: Request, res: Response) => Promise<void>;
    private readonly provider: oidc.Provider;
    private readonly siteUrl: string;

    constructor(
        @Inject(EXTENDED_PRISMA_SERVICE) private readonly prisma: PrismaStore,
        private readonly oidcProvider: OIDCProviderService,
    ) {
        this.provider = this.oidcProvider.provider;
        this.siteUrl = this.oidcProvider.siteUrl;
        this.callback = this.provider.callback();
    }

    async interaction(req: Request, res: Response): Promise<object | void> {

        const result = await this.provider.interactionDetails(req, res);

        const { params } = result;
        const query = new URLSearchParams({
            client_id: String(params.client_id), response_type: 'code',
            scope: String(params.scope ?? ''), redirect_uri: `${this.siteUrl}/yggc/callback`,
            state: result.jti, prompt: 'consent',
        });
        const url = `${this.siteUrl}/oauth/authorize?${query}`;

        /* if(result.deviceCode) {
            return res.json({
                url: url
            });
        } */

        return res.redirect(url);
    }

    async interactionCallback(req: Request, res: Response): Promise<void> {
        const { code, error, error_description } = req.query as { code: string; error: string | undefined; error_description: string | undefined; };
        const { client_id, scope } = (await this.provider.interactionDetails(req, res)).params as { client_id: string; scope: string; };

        if (error) {
            return this.provider.interactionFinished(req, res, {
                error: error,
                error_description: error_description
            }, { mergeWithLastSubmission: false });
        }

        const reject = () => this.provider.interactionFinished(req, res, {
            error: 'invalid_grant', error_description: 'Invalid or expired code',
        }, { mergeWithLastSubmission: false });
        if (typeof code !== 'string' || !code || typeof client_id !== 'string'
            || !/^[1-9][0-9]{0,19}$/.test(client_id)
            || BigInt(client_id) > 18446744073709551615n) return reject();
        const client = await this.prisma.client.client.findFirst({
            where: { id: BigInt(client_id), revoked: false },
        });
        if (!client) return reject();

        const authCode: PassportAuthCode | null = await this.prisma.client.passportAuthCode.findFirst({
            where: {
                id: code,
                client_id: BigInt(client_id),
                revoked: false,
                expires_at: {
                    gte: getDateWithTimezoneOffset()
                }
            }
        });

        if (!authCode || String(authCode.client_id) !== client_id) {
            return this.provider.interactionFinished(req, res, {
                error: "invalid_grant",
                error_description: "Invalid or expired code"
            });
        }

        const user: User | null = await this.prisma.client.user.findFirst({
            where: {
                uid: Number(authCode?.user_id),
                verified: true,
                permission: {
                    not: -1
                }
            }
        });

        if (!user) {
            return this.provider.interactionFinished(req, res, {
                error: "invalid_grant",
                error_description: "Invalid or expired code"
            });
        }

        let approved: unknown;
        try { approved = JSON.parse(authCode.scopes ?? '[]'); } catch { return reject(); }
        if (!Array.isArray(approved) || !approved.every(value => typeof value === 'string')) return reject();
        const scopes = new Set((scope ?? '').split(' ').filter(Boolean));
        const approvedScopes = new Set(approved);
        if ([...scopes].some(value => !approvedScopes.has(value))) return reject();

        if (scopes.has(YggCScopes.PROFILE_SELECT)) {
            const codeIdToUUID: CodeIdToUUID | null = await this.prisma.client.codeIdToUUID.findFirst({
                where: {
                    code_id: code
                }
            });
            if (!codeIdToUUID) {
                return this.provider.interactionFinished(req, res, {
                    error: "invalid_grant",
                    error_description: "Invalid or expired code"
                });
            }
            const uuid = await this.prisma.client.uUID.findFirst({
                where: {
                    uuid: codeIdToUUID.uuid
                },
                include: {
                    player: true
                }
            });
            if (!uuid?.player || uuid.player.uid !== user.uid) {
                return this.provider.interactionFinished(req, res, {
                    error: "invalid_grant",
                    error_description: "Invalid or expired code"
                });
            }
        }

        // Claim the one-use code atomically before creating a grant. Concurrent
        // callbacks must not mint two grants from the same authorization.
        const consumed = await this.prisma.client.passportAuthCode.updateMany({
            where: { id: code, client_id: BigInt(client_id), revoked: false,
                expires_at: { gte: getDateWithTimezoneOffset() } },
            data: { revoked: true },
        });
        if (consumed.count !== 1) return reject();

        const grant: oidc.Grant = new this.provider.Grant({
            clientId: client_id,
            accountId: user.uid.toString(),
        });

        scopes.forEach((scope) => {
            grant.addOIDCScope(scope);
            grant.addResourceScope(BS_RESOURCE_INDICATOR, scope);
        });

        grant.jti = code;
        await grant.save();

        return this.provider.interactionFinished(req, res, {
            login: {
                accountId: user.uid.toString()
            },
            consent: {
                grantId: code
            }
        }, { mergeWithLastSubmission: false });
    }

    getSiteUrl(): string {
        return this.siteUrl;
    }
}
